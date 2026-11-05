<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Async;

use App\Application\Async\OutboxEnqueuer;
use App\Domain\Async\Entity\OutboxMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1483: el outbox bajo concurrencia real. Cada worker es un proceso PHP con su
 * propia conexión MariaDB; no hay mocks del motor. El candado de fila
 * (SELECT ... FOR UPDATE) es el único mecanismo de exclusión del despachador.
 */
final class OutboxConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $dir;
    /** @var list<resource> */
    private array $procs = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->executeStatement('DELETE FROM condor_outbox_message');
        $this->dir = sys_get_temp_dir().'/outbox1483-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            if (is_resource($proc)) {
                proc_terminate($proc, 9);
                proc_close($proc);
            }
        }
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        $this->em->getConnection()->executeStatement('DELETE FROM condor_outbox_message');
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS condor_probe_1483');
        parent::tearDown();
    }

    public function testTwoWorkersDeliverEachMessageExactlyOnce(): void
    {
        $keys = array_map(static fn (int $i): string => 'once-'.$i, range(1, 6));
        foreach ($keys as $key) {
            $this->seed($key);
        }

        $results = $this->race(array_fill(0, 3, ['mode' => 'dispatch', 'handler' => 'ok', 'sleep_ms' => 40]));

        $delivered = $this->delivered();
        sort($delivered);
        self::assertSame($keys, $delivered, 'Cada clave llega al efecto externo exactamente una vez.');
        self::assertSame(6, array_sum(array_column($results, 'sent')));
        self::assertSame(6, $this->rowCount("status = 'sent' AND payload = '[]'"), 'Ack y payload borrado.');
    }

    public function testLoserOfRowLockSeesFinalStateAndDoesNotRedeliver(): void
    {
        $this->seed('lock-1');

        $results = $this->race(array_fill(0, 2, ['mode' => 'dispatch', 'handler' => 'ok', 'sleep_ms' => 500]));

        self::assertSame(['lock-1'], $this->delivered());
        self::assertSame(1, array_sum(array_column($results, 'sent')));
        self::assertSame(1, array_sum(array_column($results, 'deferred')), 'El perdedor espera el candado y ve "sent".');
    }

    public function testConcurrentFailureConsumesOneAttemptAndAppliesBackoff(): void
    {
        $this->seed('fail-1');

        $results = $this->race(array_fill(0, 2, ['mode' => 'dispatch', 'handler' => 'fail', 'sleep_ms' => 400]));

        self::assertSame(['fail-1'], $this->delivered(), 'Un solo intento real contra el proveedor.');
        self::assertSame(1, array_sum(array_column($results, 'failed')));
        $row = $this->row('fail-1');
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('pending', $row['status']);
        self::assertEqualsWithDelta(60, (int) $row['backoff'], 5, 'Backoff 30 * 2^1 s.');
    }

    public function testConcurrentLastFailureEndsDeadOnceAndErasesPayload(): void
    {
        $this->seed('dead-1');
        $this->em->getConnection()->executeStatement(
            'UPDATE condor_outbox_message SET attempts = ? WHERE idempotency_key = ?',
            [OutboxMessage::MAX_ATTEMPTS - 1, 'dead-1'],
        );

        $this->race(array_fill(0, 2, ['mode' => 'dispatch', 'handler' => 'fail', 'sleep_ms' => 400]));

        $row = $this->row('dead-1');
        self::assertSame('dead', $row['status']);
        self::assertSame(OutboxMessage::MAX_ATTEMPTS, (int) $row['attempts']);
        self::assertSame('[]', $row['payload']);
        self::assertSame(['dead-1'], $this->delivered());
    }

    public function testKilledWorkerReleasesTheLockAndAnotherRedeliversWithSameKey(): void
    {
        $this->seed('crash-1');
        $victim = $this->spawn(['mode' => 'dispatch', 'handler' => 'ok', 'sleep_ms' => 30000], 'victim');
        $this->waitReady(['victim']);
        touch($this->dir.'/go');
        $this->waitFor(fn (): bool => $this->delivered() === ['crash-1'], 'El worker debe entrar al handler.');

        proc_terminate($victim, 9);
        proc_close($victim);
        self::assertSame('pending', $this->row('crash-1')['status'], 'Sin ack no hay cambio de estado.');

        @unlink($this->dir.'/go');
        $results = $this->race([['mode' => 'dispatch', 'handler' => 'ok', 'sleep_ms' => 0]]);

        self::assertSame(1, $results[0]['sent']);
        self::assertSame(['crash-1', 'crash-1'], $this->delivered(), 'At-least-once: misma clave de idempotencia.');
        self::assertSame('sent', $this->row('crash-1')['status']);
    }

    public function testConcurrentEnqueueOfSameKeysNeverDuplicates(): void
    {
        $keys = array_map(static fn (int $i): string => 'dup-'.$i, range(1, 25));

        $results = $this->race(array_fill(0, 4, ['mode' => 'enqueue', 'keys' => $keys]));

        self::assertSame(25, $this->rowCount('1=1'));
        self::assertSame(25, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT idempotency_key) FROM condor_outbox_message'));
        foreach ($results as $r) {
            self::assertSame(25, $r['created'] + $r['conflicts'], 'Cada intento termina creado o en conflicto único, nunca duplicado.');
        }
    }

    public function testSameKeyWithDifferentContentKeepsTheFirstEnvelope(): void
    {
        $enqueuer = new OutboxEnqueuer($this->em);
        $enqueuer->enqueue('sem-1', 'probe', ['v' => 1]);
        $this->em->flush();
        $this->em->clear();
        $again = $enqueuer->enqueue('sem-1', 'otro_topic', ['v' => 2]);
        $this->em->flush();

        self::assertSame('probe', $again->topic());
        $row = $this->row('sem-1');
        self::assertSame('probe', $row['topic']);
        self::assertSame(['v' => 1], json_decode($row['payload'], true));
        self::assertSame(1, $this->rowCount('1=1'));
    }

    public function testBusinessWriteAndEnvelopeAreInvisibleUntilCommitAndGoTogether(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('CREATE TABLE condor_probe_1483 (id INT PRIMARY KEY, v VARCHAR(20)) ENGINE=InnoDB');
        $other = $this->secondConnection();

        $conn->beginTransaction();
        $conn->executeStatement('INSERT INTO condor_probe_1483 VALUES (1, ?)', ['negocio']);
        (new OutboxEnqueuer($this->em))->enqueue('atomic-1', 'probe', ['n' => 1]);
        $this->em->flush();
        self::assertSame([0, 0], $this->visibleFrom($other), 'Nada visible antes del commit.');

        $conn->rollBack();
        self::assertSame([0, 0], $this->visibleFrom($other), 'El rollback se lleva negocio y envelope.');

        $this->em->clear();
        $conn->beginTransaction();
        $conn->executeStatement('INSERT INTO condor_probe_1483 VALUES (1, ?)', ['negocio']);
        (new OutboxEnqueuer($this->em))->enqueue('atomic-1', 'probe', ['n' => 1]);
        $this->em->flush();
        $conn->commit();
        self::assertSame([1, 1], $this->visibleFrom($other), 'Tras el commit aparecen ambos a la vez.');
    }

    // ---- helpers -----------------------------------------------------------

    private function seed(string $key): void
    {
        (new OutboxEnqueuer($this->em))->enqueue($key, 'probe', ['n' => $key]);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @param list<array<string, mixed>> $workers
     * @return list<array<string, int>>
     */
    private function race(array $workers): array
    {
        $names = [];
        $pipes = [];
        foreach ($workers as $i => $cfg) {
            $names[] = 'w'.$i;
            $this->spawn($cfg, 'w'.$i, $pipes);
        }
        $this->waitReady($names);
        touch($this->dir.'/go');

        $out = [];
        foreach ($pipes as $pipe) {
            $line = stream_get_contents($pipe);
            $decoded = json_decode((string) $line, true);
            self::assertIsArray($decoded, 'Salida del worker: '.$line);
            $out[] = $decoded;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<int, resource> $pipes
     * @return resource
     */
    private function spawn(array $cfg, string $name, array &$pipes = [])
    {
        $cfg += ['keys' => [], 'ready' => $this->dir.'/'.$name.'.ready', 'go' => $this->dir.'/go', 'log' => $this->dir.'/effects.log'];
        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/Support/outbox_worker.php', json_encode($cfg, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['file', $this->dir.'/'.$name.'.err', 'w']],
            $p,
        );
        self::assertIsResource($proc);
        $pipes[] = $p[1];
        $this->procs[] = $proc;

        return $proc;
    }

    /** @param list<string> $names */
    private function waitReady(array $names): void
    {
        $this->waitFor(
            fn (): bool => count(array_filter($names, fn (string $n): bool => is_file($this->dir.'/'.$n.'.ready'))) === count($names),
            'Los workers no arrancaron: '.implode(' | ', array_map(static fn (string $f): string => (string) file_get_contents($f), glob($this->dir.'/*.err') ?: [])),
        );
    }

    private function waitFor(callable $condition, string $message): void
    {
        $deadline = microtime(true) + 20;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail($message);
            }
            usleep(20000);
        }
    }

    /** @return list<string> */
    private function delivered(): array
    {
        $file = $this->dir.'/effects.log';

        return is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
    }

    /** @return array<string, string> */
    private function row(string $key): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, attempts, topic, payload, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at) AS backoff FROM condor_outbox_message WHERE idempotency_key = ?',
            [$key],
        );
        self::assertIsArray($row);

        return array_map('strval', $row);
    }

    private function rowCount(string $where): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM condor_outbox_message WHERE '.$where);
    }

    private function secondConnection(): Connection
    {
        return DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    /** @return array{int, int} */
    private function visibleFrom(Connection $other): array
    {
        return [
            (int) $other->fetchOne('SELECT COUNT(*) FROM condor_probe_1483'),
            (int) $other->fetchOne("SELECT COUNT(*) FROM condor_outbox_message WHERE idempotency_key = 'atomic-1'"),
        ];
    }
}

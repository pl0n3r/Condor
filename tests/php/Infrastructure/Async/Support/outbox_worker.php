<?php

declare(strict_types=1);

// Worker de prueba (#1483): un proceso PHP real con su propia conexión MariaDB.
// Uso: php outbox_worker.php '<json>'. Responde una línea JSON por stdout.

use App\Application\Async\OutboxEnqueuer;
use App\Application\Async\OutboxHandler;
use App\Infrastructure\Async\OutboxDispatcher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

require dirname(__DIR__, 5).'/config/bootstrap.php';

/** @var array{mode: string, ready: string, go: string, log: string, handler: string, sleep_ms: int, keys: list<string>} $cfg */
$cfg = json_decode((string) ($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

$kernel = new App\Kernel('test', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
assert($em instanceof EntityManagerInterface);

touch($cfg['ready']);
$deadline = microtime(true) + 20;
while (!is_file($cfg['go']) && microtime(true) < $deadline) {
    usleep(1000);
}

if ($cfg['mode'] === 'enqueue') {
    $created = 0;
    $conflicts = 0;
    foreach ($cfg['keys'] as $key) {
        try {
            $em->wrapInTransaction(static function () use ($em, $key): void {
                (new OutboxEnqueuer($em))->enqueue($key, 'probe', ['n' => $key]);
            });
            ++$created;
        } catch (UniqueConstraintViolationException) {
            ++$conflicts;
            $kernel->getContainer()->get('doctrine')->resetManager();
            $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        }
    }
    echo json_encode(['created' => $created, 'conflicts' => $conflicts]), "\n";

    return;
}

$handler = new class($cfg) implements OutboxHandler {
    /** @param array<string, mixed> $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function topic(): string
    {
        return 'probe';
    }

    public function handle(string $idempotencyKey, array $payload): void
    {
        // Registro del efecto externo: lo que un proveedor real recibiría.
        file_put_contents($this->cfg['log'], $idempotencyKey."\n", FILE_APPEND | LOCK_EX);
        usleep((int) $this->cfg['sleep_ms'] * 1000);
        if ($this->cfg['handler'] === 'fail') {
            throw new RuntimeException('fallo sintético');
        }
    }
};

echo json_encode((new OutboxDispatcher($em, new NullLogger(), [$handler]))->dispatch()), "\n";

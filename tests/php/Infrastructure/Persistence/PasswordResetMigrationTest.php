<?php
declare(strict_types=1);
namespace App\Tests\Infrastructure\Persistence;
use App\Domain\Identity\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20260925040000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
require_once dirname(__DIR__, 4).'/migrations/Version20260925040000.php';

final class PasswordResetMigrationTest extends KernelTestCase
{
    public function testMariaDbConstraintsAndFailClosedRollback(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $table = $connection->createSchemaManager()->introspectTable('condor_password_reset');
        $indexes = array_map('strtolower', array_keys($table->getIndexes()));
        self::assertContains('uniq_password_reset_user', $indexes);
        self::assertContains('uniq_password_reset_token_hash', $indexes);
        $foreignTables = array_map(
            static fn ($fk): string => strtolower($fk->getReferencedTableName()->toString()),
            $table->getForeignKeys(),
        );
        self::assertContains('condor_user', $foreignTables);
        $user = new User('migration-reset@example.test', 'Migration Reset');
        $manager = static::getContainer()->get('doctrine.orm.entity_manager');
        $manager->persist($user);
        $manager->flush();
        try {
            $connection->insert('condor_password_reset', [
                'id' => '01K60RESETMIGRATION000001', 'user_id' => $user->id(),
                'token_hash' => str_repeat('d', 64), 'expires_at' => '2026-09-27 16:00:00',
                'consumed_at' => null, 'revoked_at' => null,
                'created_at' => '2026-09-27 15:00:00', 'updated_at' => '2026-09-27 15:00:00',
            ]);
            $blocked = new Version20260925040000($connection, new NullLogger());
            try {
                $blocked->down(new Schema());
                self::fail('El rollback debe abortar mientras existan tokens.');
            } catch (AbortMigration) {
                self::assertContains('condor_password_reset', $connection->createSchemaManager()->listTableNames());
            }
            $connection->delete('condor_password_reset', ['user_id' => $user->id()]);
            $down = new Version20260925040000($connection, new NullLogger());
            $down->down(new Schema());
            $this->executeSql($connection, $down->getSql());
            self::assertNotContains('condor_password_reset', $connection->createSchemaManager()->listTableNames());
        } finally {
            if (!in_array('condor_password_reset', $connection->createSchemaManager()->listTableNames(), true)) {
                $up = new Version20260925040000($connection, new NullLogger());
                $up->up(new Schema());
                $this->executeSql($connection, $up->getSql());
            }
            $connection->delete('condor_password_reset', ['user_id' => $user->id()]);
            $connection->delete('condor_user', ['id' => $user->id()]);
        }
    }
    private function executeSql(Connection $connection, array $queries): void
    {
        foreach ($queries as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}

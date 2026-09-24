<?php

declare(strict_types=1);

namespace App\Tests\Shared\Runtime;

use App\Shared\Runtime\DatabaseDsn;
use Doctrine\DBAL\Tools\DsnParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseDsnTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function doctrineParityCases(): iterable
    {
        yield 'credenciales codificadas' => [
            'mysql://user%40tenant:p%3Ass%40word%2Fok@db.example:3307/condor_db?charset=utf8mb4',
        ];
        yield 'socket y charset' => [
            'mariadb://condor:secret@localhost/condor_prod'
            .'?unix_socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock&charset=utf8mb4',
        ];
    }

    #[DataProvider('doctrineParityCases')]
    public function testMatchesDoctrineDsnParserForConnectionParams(
        string $url,
    ): void {
        $actual = DatabaseDsn::parse($url);
        $expected = (new DsnParser([
            'mysql' => 'pdo_mysql',
            'mariadb' => 'pdo_mysql',
        ]))->parse($url);

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $actual);
            self::assertSame($value, $actual[$key]);
        }
    }

    public function testPreservesSocketAndLeavesPortUnsetForDoctrine(): void
    {
        $params = DatabaseDsn::parse(
            'mariadb://condor:secret@localhost/condor_prod'
            .'?unix_socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock&charset=utf8mb4'
        );

        self::assertSame('pdo_mysql', $params['driver']);
        self::assertSame(
            '/var/run/mysqld/mysqld.sock',
            $params['unix_socket']
        );
        self::assertSame('utf8mb4', $params['charset']);
        self::assertArrayNotHasKey('port', $params);
    }

    public function testRejectsUnsupportedDatabaseScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DatabaseDsn::parse('pgsql://condor:secret@db.internal/condor_prod');
    }
}

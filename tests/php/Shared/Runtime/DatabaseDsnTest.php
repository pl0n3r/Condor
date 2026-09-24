<?php

declare(strict_types=1);

namespace App\Tests\Shared\Runtime;

use App\Shared\Runtime\DatabaseDsn;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabaseDsnTest extends TestCase
{
    public function testParsesEncodedCredentialsWithDoctrineSemantics(): void
    {
        $params = DatabaseDsn::parse(
            'mysql://user%40tenant:p%3Ass%40word%2Fok@db.example:3307/condor_db?charset=utf8mb4'
        );

        self::assertSame('pdo_mysql', $params['driver']);
        self::assertSame('user@tenant', $params['user']);
        self::assertSame('p:ss@word/ok', $params['password']);
        self::assertSame('db.example', $params['host']);
        self::assertSame(3307, $params['port']);
        self::assertSame('condor_db', $params['dbname']);
        self::assertSame('utf8mb4', $params['charset']);
    }

    public function testPreservesSocketAndLeavesPortUnsetForDoctrine(): void
    {
        $params = DatabaseDsn::parse(
            'mariadb://condor:secret@localhost/condor_prod'
            .'?unix_socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock&charset=utf8mb4'
        );

        self::assertSame('pdo_mysql', $params['driver']);
        self::assertSame('condor_prod', $params['dbname']);
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

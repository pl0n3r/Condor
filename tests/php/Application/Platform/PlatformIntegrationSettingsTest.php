<?php

declare(strict_types=1);

namespace App\Tests\Application\Platform;

use App\Application\Platform\PlatformIntegrationSettings;
use App\Infrastructure\Persistence\PlatformIntegrationSettingRepository;
use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** All values and owner identities here are generated, synthetic fixtures. */
final class PlatformIntegrationSettingsTest extends KernelTestCase
{
    private function service(string $master): array
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        return [new PlatformIntegrationSettings(
            new PlatformIntegrationSettingRepository($connection), $master,
        ), $connection];
    }

    public function testSecretIsCiphertextOnlyAndSafeSnapshotExcludesIt(): void
    {
        [$settings, $db] = $this->service(bin2hex(random_bytes(32)));
        $name = 'fixture.secret.'.bin2hex(random_bytes(4));
        $synthetic = 'fixture-secret-'.bin2hex(random_bytes(10));
        $settings->saveSecret($name, $synthetic, 'platform_owner_fixture', true);
        $row = $db->fetchAssociative(
            'SELECT plain_value, encrypted_value, nonce, key_version, secret_suffix, updated_by '
            .'FROM condor_platform_integration_setting WHERE setting_key = ?', [$name],
        );
        self::assertIsArray($row);
        self::assertNull($row['plain_value']);
        self::assertNotSame($synthetic, $row['encrypted_value']);
        self::assertStringNotContainsString($synthetic, (string) $row['encrypted_value']);
        self::assertSame(1, (int) $row['key_version']);
        self::assertSame(24, strlen((string) base64_decode($row['nonce'], true)));
        self::assertSame(substr($synthetic, -4), $row['secret_suffix']);

        $view = $settings->get($name, 'platform_owner_fixture', true);
        self::assertIsArray($view);
        self::assertTrue($view['configured']);
        self::assertTrue($view['is_secret']);
        self::assertSame('••••'.substr($synthetic, -4), $view['last4']);
        self::assertNotEmpty($view['updated_at']);
        self::assertArrayNotHasKey('value', $view);
        self::assertStringNotContainsString($synthetic, json_encode($settings->all('platform_owner_fixture', true), JSON_THROW_ON_ERROR));
        $audit = $db->fetchAllAssociative(
            'SELECT setting_key, action, actor_id FROM condor_platform_setting_audit WHERE setting_key = ?',
            [$name],
        );
        self::assertCount(1, $audit);
        self::assertSame('UPSERT', $audit[0]['action']);
        self::assertSame('platform_owner_fixture', $audit[0]['actor_id']);
    }

    public function testInvalidMasterKeysAndUnauthorizedOwnersAreRejected(): void
    {
        [$settings, $db] = $this->service('');
        $name = 'fixture.denied.'.bin2hex(random_bytes(4));
        foreach (['', 'change-me-local-only', 'example-app-secret'] as $placeholder) {
            $invalid = new PlatformIntegrationSettings(
                new PlatformIntegrationSettingRepository($db), $placeholder,
            );
            try {
                $invalid->saveSecret($name, 'fixture-secret-00000001', 'platform_owner_fixture', true);
                self::fail('La clave maestra de ejemplo fue aceptada.');
            } catch (DomainException) {
                self::assertNull($settings->get($name, 'platform_owner_fixture', true));
            }
        }
        try {
            $settings->saveValue($name, 'una prueba', 'tenant_owner', false);
            self::fail('Un propietario de tenant editó configuración global.');
        } catch (DomainException) {
            self::assertNull($settings->get($name, 'platform_owner_fixture', true));
        }
    }

    public function testPlainValueOverwriteDeleteAndAuditHistory(): void
    {
        [$settings, $db] = $this->service(bin2hex(random_bytes(32)));
        $name = 'fixture.plain.'.bin2hex(random_bytes(4));
        $settings->saveValue($name, 'valor ficticio', 'platform_owner_fixture', true);
        $plain = $settings->get($name, 'platform_owner_fixture', true);
        self::assertSame('valor ficticio', $plain['value']);
        self::assertFalse($plain['is_secret']);
        $settings->saveSecret($name, 'fixture-secret-z9876', 'platform_owner_fixture', true);
        $secret = $settings->get($name, 'platform_owner_fixture', true);
        self::assertArrayNotHasKey('value', $secret);
        $settings->delete($name, 'platform_owner_fixture', true);
        self::assertNull($settings->get($name, 'platform_owner_fixture', true));
        self::assertSame(
            ['UPSERT', 'UPSERT', 'DELETE'],
            $db->fetchFirstColumn(
                'SELECT action FROM condor_platform_setting_audit WHERE setting_key = ? ORDER BY id',
                [$name],
            ),
        );
        // An audit record is not erased when the setting is deleted.
        self::assertSame(3, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_platform_setting_audit WHERE setting_key = ?', [$name],
        ));
    }
}

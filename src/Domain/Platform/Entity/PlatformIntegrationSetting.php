<?php

declare(strict_types=1);

namespace App\Domain\Platform\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/**
 * Platform-only configuration; no tenant identifier or secret-value getter.
 * The encrypted columns are opaque and never exposed to a public serializer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'condor_platform_integration_setting')]
final class PlatformIntegrationSetting
{
    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', type: 'string', length: 100)]
    private string $key;

    #[ORM\Column(name: 'plain_value', type: 'text', nullable: true)]
    private ?string $plainValue = null;

    #[ORM\Column(name: 'encrypted_value', type: 'text', nullable: true)]
    private ?string $encryptedValue = null;

    #[ORM\Column(name: 'nonce', type: 'string', length: 32, nullable: true)]
    private ?string $nonce = null;

    #[ORM\Column(name: 'key_version', type: 'smallint', nullable: true)]
    private ?int $keyVersion = null;

    #[ORM\Column(name: 'secret_suffix', type: 'string', length: 4, nullable: true)]
    private ?string $secretSuffix = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'updated_by', type: 'string', length: 80)]
    private string $updatedBy;

    /** ORM can hydrate the private ciphertext/nonce; this constructor never accepts secrets. */
    public function __construct(string $key, string $updatedBy)
    {
        self::assertKey($key);
        $this->key = $key;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function assertKey(string $key): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $key) !== 1) {
            throw new DomainException('Clave de ajuste de plataforma inválida.');
        }
    }
}

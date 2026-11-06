<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Platform\Entity\PlatformIntegrationSetting;
use App\Infrastructure\Persistence\PlatformIntegrationSettingRepository;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Backend-only platform-owner service. There are deliberately no HTTP routes,
 * tenant-scoped adapters, transports or secret-read/decryption operations.
 */
final readonly class PlatformIntegrationSettings
{
    private const KEY_VERSION = 1;
    private const HKDF_CONTEXT = 'condor-platform-settings-v1';

    public function __construct(
        private PlatformIntegrationSettingRepository $repository,
        #[Autowire('%env(APP_SECRET)%')]
        private string $appSecret,
    ) {
    }

    public function saveValue(string $name, string $value, string $actor, bool $platformOwner): void
    {
        self::authorize($name, $actor, $platformOwner);
        if (strlen($value) > 4096 || str_contains($value, "\0")) {
            throw new DomainException('Valor de ajuste inválido.');
        }
        $this->repository->put($name, $value, null, null, null, null, $actor);
    }

    public function saveSecret(string $name, string $secret, string $actor, bool $platformOwner): void
    {
        self::authorize($name, $actor, $platformOwner);
        if (strlen($secret) < 8 || strlen($secret) > 8192 || str_contains($secret, "\0")) {
            throw new DomainException('Secreto inválido.');
        }
        // Four UTF-8 code points, not four bytes. Reject malformed UTF-8.
        $characters = preg_split('//u', $secret, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false || count($characters) < 4) {
            throw new DomainException('Codificación o longitud de secreto inválida.');
        }
        $suffix = implode('', array_slice($characters, -4));
        if (!function_exists('sodium_crypto_secretbox') || !$this->hasSafeMasterKey()) {
            throw new DomainException('No existe una clave maestra válida para cifrar secretos.');
        }

        $key = hash_hkdf('sha256', $this->appSecret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::HKDF_CONTEXT);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $key);
        sodium_memzero($key);
        $this->repository->put(
            $name, null, base64_encode($ciphertext), base64_encode($nonce),
            self::KEY_VERSION, $suffix, $actor,
        );
    }

    /** @return array<string,mixed>|null */
    public function get(string $name, string $actor, bool $platformOwner): ?array
    {
        self::authorize($name, $actor, $platformOwner);
        return $this->repository->getSafe($name);
    }

    /** @return list<array<string,mixed>> */
    public function all(string $actor, bool $platformOwner): array
    {
        self::authorizeActor($actor, $platformOwner);
        return $this->repository->allSafe();
    }

    public function delete(string $name, string $actor, bool $platformOwner): void
    {
        self::authorize($name, $actor, $platformOwner);
        $this->repository->delete($name, $actor);
    }

    private function hasSafeMasterKey(): bool
    {
        $value = trim($this->appSecret);
        return strlen($value) >= 32
            && preg_match('/change.?me|example|placeholder|replace.?me|sample|your.?secret/i', $value) !== 1;
    }

    private static function authorize(string $name, string $actor, bool $platformOwner): void
    {
        self::authorizeActor($actor, $platformOwner);
        PlatformIntegrationSetting::assertKey($name);
    }

    private static function authorizeActor(string $actor, bool $platformOwner): void
    {
        // A future controller must supply an independently verified platform-owner
        // identity, never a client-provided flag or tenant membership.
        if (!$platformOwner || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}\z/D', $actor) !== 1) {
            throw new DomainException('Solo el propietario de la plataforma puede gestionar ajustes.');
        }
    }
}

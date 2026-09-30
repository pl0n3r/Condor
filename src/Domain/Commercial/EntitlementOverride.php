<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use DateTimeImmutable;
use DomainException;

final readonly class EntitlementOverride
{
    private const NAMESPACES = ['capability', 'addon', 'limit'];
    private const BASE_CONTROLS = ['security', 'privacy', 'backup', 'recovery', 'integrity'];

    public function __construct(
        private string $tenantId,
        private string $namespace,
        private string $key,
        private bool|int|string|null $value,
        private string $reason,
        private string $actor,
        private DateTimeImmutable $createdAt,
    ) {
        $tenantId = trim($tenantId);
        $namespace = strtolower(trim($namespace));
        $key = strtolower(trim($key));
        $reason = trim($reason);
        $actor = trim($actor);

        if ($tenantId === '' || $reason === '' || $actor === '') {
            throw new DomainException('Override de entitlement incompleto.');
        }
        if (!in_array($namespace, self::NAMESPACES, true)) {
            throw new DomainException('Namespace de entitlement inválido.');
        }
        if (
            preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $key) !== 1
            || in_array($key, self::BASE_CONTROLS, true)
        ) {
            throw new DomainException('Key de entitlement no comercial o inválida.');
        }
        if ((is_int($value) && $value < 0) || (is_string($value) && mb_strlen($value, 'UTF-8') > 120)) {
            throw new DomainException('Valor de override inválido.');
        }

        $this->tenantId = $tenantId;
        $this->namespace = $namespace;
        $this->key = $key;
        $this->reason = $reason;
        $this->actor = $actor;
    }

    public function tenantId(): string { return $this->tenantId; }
    public function entitlementNamespace(): string { return $this->namespace; }
    public function key(): string { return $this->key; }
    public function value(): bool|int|string|null { return $this->value; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    /** @return array{namespace:string,key:string,value:bool|int|string|null,reason:string,actor:string,created_at:string} */
    public function snapshot(): array
    {
        return [
            'namespace' => $this->namespace,
            'key' => $this->key,
            'value' => $this->value,
            'reason' => $this->reason,
            'actor' => $this->actor,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}

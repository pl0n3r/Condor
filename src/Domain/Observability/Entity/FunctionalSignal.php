<?php

declare(strict_types=1);

namespace App\Domain\Observability\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_functional_signal')]
#[ORM\Index(name: 'idx_functional_signal_type_created', columns: ['type', 'created_at'])]
#[ORM\Index(name: 'idx_functional_signal_created_type', columns: ['created_at', 'type'])]
#[ORM\Index(name: 'idx_functional_signal_tenant', columns: ['tenant_id'])]
class FunctionalSignal
{
    public const TENANT_CREATED = 'tenant_created';
    public const LOGIN_SUCCESS = 'login_success';
    public const LOGIN_FAILURE = 'login_failure';
    public const AUTHORIZATION_DENIED = 'authorization_denied';
    public const ROLE_MODIFIED = 'role_modified';

    /** @var list<string> */
    private const TYPES = [
        self::TENANT_CREATED,
        self::LOGIN_SUCCESS,
        self::LOGIN_FAILURE,
        self::AUTHORIZATION_DENIED,
        self::ROLE_MODIFIED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(type: 'string', length: 40)]
    private string $type;

    #[ORM\Column(name: 'tenant_id', type: 'string', length: 26, nullable: true)]
    private ?string $tenantId;

    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @param array<string, scalar> $context */
    public function __construct(
        string $type,
        ?string $tenantId = null,
        array $context = [],
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Tipo de señal funcional desconocido: "%s".', $type),
            );
        }

        $this->id = UlidFactory::new();
        $this->type = $type;
        $this->tenantId = $tenantId;
        $this->context = $context;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    /** @return array<string, scalar> */
    public function context(): array
    {
        return $this->context;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

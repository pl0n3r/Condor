<?php

declare(strict_types=1);

namespace App\Domain\Cms\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_cms_block')]
final class CmsBlock
{
    /** @var list<string> */
    private const ALLOWED_TYPES = ['text', 'image', 'hero', 'cta', 'gallery', 'divider'];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: CmsPage::class)]
    #[ORM\JoinColumn(name: 'page_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CmsPage $page;

    #[ORM\Column(name: 'block_type', type: 'string', length: 32)]
    private string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['unsigned' => true])]
    private int $sortOrder;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(
        Tenant $tenant,
        CmsPage $page,
        string $type,
        array $payload = [],
        int $sortOrder = 0,
    ) {
        if ($tenant->id() !== $page->tenant()->id()) {
            throw new DomainException('Un bloque CMS no puede pertenecer a una página de otro tenant.');
        }
        if ($page->isPublished()) {
            throw new DomainException('No se pueden añadir bloques a una página publicada.');
        }

        $normalizedType = strtolower(trim($type));
        if (!in_array($normalizedType, self::ALLOWED_TYPES, true)) {
            throw new DomainException('Tipo de bloque CMS no permitido.');
        }
        if ($sortOrder < 0) {
            throw new DomainException('El orden del bloque CMS no puede ser negativo.');
        }

        self::assertJsonSafe($payload);

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->page = $page;
        $this->type = $normalizedType;
        $this->payload = $payload;
        $this->sortOrder = $sortOrder;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function page(): CmsPage
    {
        return $this->page;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /** @param array<string, mixed> $payload */
    public function update(string $type, array $payload, int $sortOrder): void
    {
        if ($this->page->isPublished()) {
            throw new DomainException('No se puede editar un bloque de una página publicada.');
        }

        $normalizedType = strtolower(trim($type));
        if (!in_array($normalizedType, self::ALLOWED_TYPES, true)) {
            throw new DomainException('Tipo de bloque CMS no permitido.');
        }
        if ($sortOrder < 0) {
            throw new DomainException('El orden del bloque CMS no puede ser negativo.');
        }

        self::assertJsonSafe($payload);
        $this->type = $normalizedType;
        $this->payload = $payload;
        $this->sortOrder = $sortOrder;
    }

    /** @param array<array-key, mixed> $payload */
    private static function assertJsonSafe(array $payload): void
    {
        array_walk_recursive(
            $payload,
            static function (mixed $value): void {
                if (!is_null($value) && !is_scalar($value)) {
                    throw new DomainException('El payload CMS solo admite valores JSON inertes.');
                }
            },
        );
    }
}

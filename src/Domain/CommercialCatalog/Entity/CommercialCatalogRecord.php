<?php

declare(strict_types=1);

namespace App\Domain\CommercialCatalog\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\MappedSuperclass]
abstract class CommercialCatalogRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'catalog_key', type: 'string', length: 80)]
    private string $key;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $key, string $name)
    {
        $this->id = UlidFactory::new();
        $this->key = self::normalizeKey($key);
        $this->name = self::normalizeName($name);
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    final public function id(): string
    {
        return $this->id;
    }

    final public function key(): string
    {
        return $this->key;
    }

    final public function name(): string
    {
        return $this->name;
    }

    final public function isActive(): bool
    {
        return $this->active;
    }

    final public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->touch();
    }

    final public function activate(): void
    {
        if ($this->active) {
            return;
        }

        $this->active = true;
        $this->touch();
    }

    final protected function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (
            $key === ''
            || mb_strlen($key, 'UTF-8') > 80
            || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D', $key) !== 1
        ) {
            throw new DomainException(
                'La clave comercial debe ser estable y usar minúsculas, números, punto, guion o guion bajo.',
            );
        }

        return $key;
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException(
                'El nombre comercial es obligatorio y admite máximo 160 caracteres.',
            );
        }

        return $name;
    }
}

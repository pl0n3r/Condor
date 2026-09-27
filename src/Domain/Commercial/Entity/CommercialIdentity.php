<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Shared\Id\UlidFactory;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\MappedSuperclass]
abstract class CommercialIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'catalog_key', type: 'string', length: 64)]
    private string $key;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    public function __construct(string $key, string $name)
    {
        $key = strtolower(trim($key));
        $name = trim($name);
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $key) !== 1) {
            throw new DomainException('Clave comercial inválida.');
        }
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException('Nombre comercial inválido.');
        }

        $this->id = UlidFactory::new();
        $this->key = $key;
        $this->name = $name;
    }

    final public function id(): string { return $this->id; }
    final public function key(): string { return $this->key; }
    final public function name(): string { return $this->name; }
    final public function isActive(): bool { return $this->active; }
    final public function deactivate(): void { $this->active = false; }
}

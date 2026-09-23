<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\MappedSuperclass]
abstract class NamedCommercialItem extends CommercialItem
{
    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    protected function __construct(
        Tenant $tenant,
        string $name,
        string $slug,
        string $label,
    ) {
        parent::__construct($tenant);
        $this->setIdentity($name, $slug, $label);
    }

    final public function name(): string
    {
        return $this->name;
    }

    final public function slug(): string
    {
        return $this->slug;
    }

    final protected function updateIdentity(
        string $name,
        string $slug,
        string $label,
    ): void {
        $this->setIdentity($name, $slug, $label);
        $this->touch();
    }

    private function setIdentity(
        string $name,
        string $slug,
        string $label,
    ): void {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException(sprintf(
                'El nombre de %s es obligatorio y admite máximo 160 caracteres.',
                $label,
            ));
        }

        $slug = strtolower(trim($slug));
        if (
            $slug === ''
            || mb_strlen($slug, 'UTF-8') > 120
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        ) {
            throw new DomainException(sprintf(
                'El slug de %s debe usar letras minúsculas, números y guiones.',
                $label,
            ));
        }

        $this->name = $name;
        $this->slug = $slug;
    }
}

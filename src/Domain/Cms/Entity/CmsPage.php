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
#[ORM\Table(
    name: 'condor_cms_page',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_cms_page_tenant_slug', columns: ['tenant_id', 'slug']),
    ],
)]
final class CmsPage
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: CmsTheme::class)]
    #[ORM\JoinColumn(name: 'theme_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CmsTheme $theme;

    #[ORM\Column(type: 'string', length: 180)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 200)]
    private string $title;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, CmsTheme $theme, string $slug, string $title)
    {
        self::assertSameTenant($tenant, $theme->tenant());

        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $normalizedSlug) !== 1) {
            throw new DomainException('El slug de la página CMS no es válido.');
        }

        $normalizedTitle = trim($title);
        if ($normalizedTitle === '') {
            throw new DomainException('El título de la página CMS es obligatorio.');
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->theme = $theme;
        $this->slug = $normalizedSlug;
        $this->title = $normalizedTitle;
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

    public function theme(): CmsTheme
    {
        return $this->theme;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->title;
    }

    private static function assertSameTenant(Tenant $left, Tenant $right): void
    {
        if ($left->id() !== $right->id()) {
            throw new DomainException('Una página CMS no puede usar un tema de otro tenant.');
        }
    }
}

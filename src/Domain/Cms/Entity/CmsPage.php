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
#[ORM\Table(name: 'condor_cms_page')]
#[ORM\UniqueConstraint(name: 'uniq_cms_page_tenant_slug', columns: ['tenant_id', 'slug'])]
final class CmsPage
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

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

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, CmsTheme $theme, string $slug, string $title)
    {
        self::assertSameTenant($tenant, $theme->tenant());

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->theme = $theme;
        $this->slug = self::normalizeSlug($slug);
        $this->title = self::normalizeTitle($title);
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

    public function status(): string
    {
        return $this->status;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function updateDraft(
        CmsTheme $theme,
        string $slug,
        string $title,
    ): void {
        if ($this->isPublished()) {
            throw new DomainException(
                'Una página publicada no se edita directamente; crea una nueva revisión.',
            );
        }

        self::assertSameTenant($this->tenant, $theme->tenant());
        $this->theme = $theme;
        $this->slug = self::normalizeSlug($slug);
        $this->title = self::normalizeTitle($title);
    }

    public function publish(): void
    {
        if ($this->isPublished()) {
            return;
        }

        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (
            $slug === ''
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1
        ) {
            throw new DomainException('El slug de la página CMS no es válido.');
        }

        return $slug;
    }

    private static function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 200) {
            throw new DomainException(
                'El título de la página CMS es obligatorio y admite máximo 200 caracteres.',
            );
        }

        return $title;
    }

    private static function assertSameTenant(Tenant $left, Tenant $right): void
    {
        if ($left->id() !== $right->id()) {
            throw new DomainException('Una página CMS no puede usar un tema de otro tenant.');
        }
    }
}

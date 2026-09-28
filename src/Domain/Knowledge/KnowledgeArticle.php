<?php

declare(strict_types=1);

namespace App\Domain\Knowledge;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use JsonException;
use Throwable;

final class KnowledgeArticle
{
    private const STATES = ['draft', 'review', 'approved', 'published', 'superseded', 'archived'];
    private const VISIBILITIES = ['public', 'customer', 'staff'];
    private const AUDIENCES = ['public', 'customer', 'staff'];
    private const REQUIRED_KEYS = [
        'id', 'version', 'state', 'visibility', 'audience', 'locale', 'scope',
        'title', 'body', 'owner_ref', 'source_ref', 'tags', 'modules',
        'product_version_refs', 'capability_refs', 'reviewed_at', 'stale_after',
    ];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['review'],
        'review' => ['draft', 'approved'],
        'approved' => ['review', 'published'],
        'published' => ['superseded', 'archived'],
        'superseded' => ['archived'],
        'archived' => [],
    ];

    /**
     * @param list<string> $tags
     * @param list<string> $modules
     * @param list<string> $productVersionRefs
     * @param list<string> $capabilityRefs
     */
    private function __construct(
        private readonly string $id,
        private readonly int $version,
        private readonly string $state,
        private readonly string $visibility,
        private readonly string $audience,
        private readonly string $locale,
        private readonly string $scope,
        private readonly string $title,
        private readonly string $body,
        private readonly string $ownerRef,
        private readonly string $sourceRef,
        private readonly array $tags,
        private readonly array $modules,
        private readonly array $productVersionRefs,
        private readonly array $capabilityRefs,
        private readonly ?DateTimeImmutable $reviewedAt,
        private readonly ?DateTimeImmutable $staleAfter,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertExactKeys($payload);

        $state = self::closedString($payload['state'], self::STATES, 'Estado de conocimiento inválido.');
        $visibility = self::closedString($payload['visibility'], self::VISIBILITIES, 'Visibilidad de conocimiento inválida.');
        $audience = self::closedString($payload['audience'], self::AUDIENCES, 'Audiencia de conocimiento inválida.');
        $scope = self::normalizeScope($payload['scope']);
        self::assertVisibilityScope($visibility, $audience, $scope);

        $reviewedAt = self::dateOrNull($payload['reviewed_at']);
        $staleAfter = self::dateOrNull($payload['stale_after']);
        if (($reviewedAt === null) !== ($staleAfter === null)) {
            throw new DomainException('Freshness debe definir reviewed_at y stale_after juntos.');
        }
        if ($reviewedAt !== null && $staleAfter !== null && $staleAfter <= $reviewedAt) {
            throw new DomainException('stale_after debe ser posterior a reviewed_at.');
        }

        return new self(
            self::identifier($payload['id'], 'Identificador de conocimiento inválido.'),
            self::positiveInt($payload['version'], 'La versión debe ser positiva.'),
            $state,
            $visibility,
            $audience,
            self::locale($payload['locale']),
            $scope,
            self::text($payload['title'], 180, 'Título de conocimiento inválido.'),
            self::text($payload['body'], 20000, 'Contenido de conocimiento inválido.'),
            self::reference($payload['owner_ref'], 'Owner de conocimiento inválido.'),
            self::reference($payload['source_ref'], 'Fuente de conocimiento inválida.'),
            self::list($payload['tags'], '/^[a-z][a-z0-9._-]{0,63}$/D', 'Tag de conocimiento inválido.'),
            self::list($payload['modules'], '/^[a-z][a-z0-9._-]{0,63}$/D', 'Módulo de conocimiento inválido.'),
            self::list($payload['product_version_refs'], '/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,159}$/D', 'Referencia product_version inválida.'),
            self::list($payload['capability_refs'], '/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,159}$/D', 'Referencia capability inválida.'),
            $reviewedAt,
            $staleAfter,
        );
    }

    public function state(): string { return $this->state; }
    public function version(): int { return $this->version; }

    public function isStaleAt(DateTimeImmutable $at): bool
    {
        return $this->reviewedAt === null
            || $this->staleAfter === null
            || $at >= $this->staleAfter;
    }

    public function transitionTo(string $nextState, DateTimeImmutable $at): self
    {
        $nextState = self::closedString($nextState, self::STATES, 'Estado de conocimiento inválido.');
        if (!in_array($nextState, self::TRANSITIONS[$this->state], true)) {
            throw new DomainException(sprintf('Transición de conocimiento inválida: %s -> %s.', $this->state, $nextState));
        }
        if ($nextState === 'published' && $this->isStaleAt($at)) {
            throw new DomainException('No se puede publicar conocimiento stale o sin revisión vigente.');
        }

        return new self(
            $this->id,
            $this->version + 1,
            $nextState,
            $this->visibility,
            $this->audience,
            $this->locale,
            $this->scope,
            $this->title,
            $this->body,
            $this->ownerRef,
            $this->sourceRef,
            $this->tags,
            $this->modules,
            $this->productVersionRefs,
            $this->capabilityRefs,
            $this->reviewedAt,
            $this->staleAfter,
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'state' => $this->state,
            'visibility' => $this->visibility,
            'audience' => $this->audience,
            'locale' => $this->locale,
            'scope' => $this->scope,
            'title' => $this->title,
            'body' => $this->body,
            'owner_ref' => $this->ownerRef,
            'source_ref' => $this->sourceRef,
            'tags' => $this->tags,
            'modules' => $this->modules,
            'product_version_refs' => $this->productVersionRefs,
            'capability_refs' => $this->capabilityRefs,
            'reviewed_at' => self::canonicalDate($this->reviewedAt),
            'stale_after' => self::canonicalDate($this->staleAfter),
        ];
    }

    public function versionFingerprint(): string
    {
        try {
            return hash('sha256', json_encode($this->snapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new DomainException('No se pudo serializar el snapshot de conocimiento.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertExactKeys(array $payload): void
    {
        $keys = array_keys($payload);
        sort($keys);
        $required = self::REQUIRED_KEYS;
        sort($required);
        if ($keys !== $required) {
            throw new DomainException('Payload de conocimiento incompleto o con campos no soportados.');
        }
    }

    /**
     * @param mixed $value
     * @param list<string> $allowed
     */
    private static function closedString(mixed $value, array $allowed, string $message): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new DomainException($message);
        return $value;
    }

    private static function normalizeScope(mixed $value): string
    {
        if (!is_string($value)) throw new DomainException('Scope de conocimiento inválido.');
        $value = trim($value);
        if ($value === 'global') return $value;
        if (preg_match('/^tenant:[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new DomainException('Scope de conocimiento inválido.');
        }
        return $value;
    }

    private static function assertVisibilityScope(string $visibility, string $audience, string $scope): void
    {
        $tenantScoped = str_starts_with($scope, 'tenant:');
        if ($visibility === 'public' && $tenantScoped) {
            throw new DomainException('Contenido tenant-scoped no puede ser público.');
        }
        if ($audience === 'public' && $tenantScoped) {
            throw new DomainException('Audiencia pública no puede mezclarse con scope de tenant.');
        }
        if ($visibility === 'customer' && $audience === 'staff') {
            throw new DomainException('Contenido para staff no puede exponerse con visibilidad customer.');
        }
    }

    private static function locale(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $value) !== 1) {
            throw new DomainException('Locale de conocimiento inválido.');
        }
        return $value;
    }

    private static function identifier(mixed $value, string $message): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', trim($value)) !== 1) {
            throw new DomainException($message);
        }
        return trim($value);
    }

    private static function reference(mixed $value, string $message): string
    {
        if (!is_string($value)) throw new DomainException($message);
        $value = trim($value);
        if ($value === '' || !self::lengthAtMost($value, 200) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@-]*$/D', $value) !== 1) {
            throw new DomainException($message);
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $message): int
    {
        if (!is_int($value) || $value < 1) throw new DomainException($message);
        return $value;
    }

    private static function text(mixed $value, int $max, string $message): string
    {
        if (!is_string($value)) throw new DomainException($message);
        $value = trim($value);
        if ($value === '' || !self::lengthAtMost($value, $max)) throw new DomainException($message);
        return $value;
    }

    private static function lengthAtMost(string $value, int $max): bool
    {
        $length = iconv_strlen($value, 'UTF-8');
        return $length !== false && $length <= $max;
    }

    /** @return list<string> */
    private static function list(mixed $value, string $pattern, string $message): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) throw new DomainException($message);
        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) throw new DomainException($message);
            $item = trim($item);
            if (preg_match($pattern, $item) !== 1) throw new DomainException($message);
            $normalized[$item] = true;
        }
        $items = array_keys($normalized);
        sort($items, SORT_STRING);
        return $items;
    }

    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) return null;
        if (!is_string($value) || trim($value) === '') throw new DomainException('Fecha de freshness inválida.');
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new DomainException('Fecha de freshness inválida.', previous: $exception);
        }
    }

    private static function canonicalDate(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Owner-only read model of the platform audit ledger. Never exposes the
 * stored context verbatim: writers may legitimately keep private metadata.
 */
final class PlatformAuditController extends PlatformOwnerApiController
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 1000;

    /** @var list<string> */
    private const PUBLIC_FIELDS = [
        'plan_key', 'version', 'monthly_amount', 'annual_amount',
        'quote_required', 'grant_count',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/adminpl0n3r/api/auditoria',
        name: 'platform_owner_audit_index',
        methods: ['GET'],
    )]
    public function index(Request $request): JsonResponse
    {
        // Authenticate before consulting the ledger or validating filters.
        $this->platformOwner();

        $page = $this->page((string) $request->query->get('page', '1'));
        $actor = trim((string) $request->query->get('actor', ''));
        $action = trim((string) $request->query->get('action', ''));
        $type = trim((string) $request->query->get('type', ''));
        $from = $this->date((string) $request->query->get('from', ''));
        $through = $this->date((string) $request->query->get('through', ''));

        if (
            ($actor !== '' && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $actor) !== 1)
            || ($action !== '' && preg_match('/\A[a-z][a-z0-9._-]{0,119}\z/D', $action) !== 1)
            || ($type !== '' && (strlen($type) > 160 || strpbrk($type, "\r\n\0") !== false))
            || ($from !== null && $through !== null && $from > $through)
        ) {
            throw new BadRequestHttpException('Filtros de auditoría inválidos.');
        }

        $db = $this->entityManager->getConnection();
        $where = ['1 = 1'];
        $params = [];
        if ($actor !== '') {
            $where[] = 'actor_user_id = :actor';
            $params['actor'] = $actor;
        }
        if ($action !== '') {
            $where[] = 'action = :action';
            $params['action'] = $action;
        }
        if ($type !== '') {
            $where[] = 'entity_type = :type';
            $params['type'] = $type;
        }
        if ($from !== null) {
            $where[] = 'created_at >= :from';
            $params['from'] = $from.' 00:00:00';
        }
        if ($through !== null) {
            // Half-open range avoids excluding events at the end of the day.
            $where[] = 'created_at < :through';
            $params['through'] = (new DateTimeImmutable(
                $through.' 00:00:00', new DateTimeZone('UTC'),
            ))->modify('+1 day')->format('Y-m-d H:i:s');
        }

        $predicate = implode(' AND ', $where);
        $total = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_platform_audit_event WHERE '.$predicate,
            $params,
        );
        $query = $db->createQueryBuilder()
            ->select('id', 'actor_user_id', 'action', 'entity_type', 'entity_id', 'context', 'created_at')
            ->from('condor_platform_audit_event')
            ->where($predicate)
            ->setParameters($params)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);

        $items = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $context = json_decode((string) $row['context'], true);
            if (!is_array($context)) {
                $context = [];
            }
            $items[] = [
                'id' => (string) $row['id'],
                'actor' => (string) $row['actor_user_id'],
                'created_at' => (new DateTimeImmutable(
                    (string) $row['created_at'], new DateTimeZone('UTC'),
                ))->format('Y-m-d\TH:i:s\Z'),
                'action' => (string) $row['action'],
                'object' => [
                    'type' => (string) $row['entity_type'],
                    'id' => self::publicEntityId((string) $row['entity_id']),
                ],
                'before' => self::publicSnapshot($context['old'] ?? null),
                'after' => self::publicSnapshot($context['new'] ?? null),
            ];
        }

        $response = $this->json([
            'items' => $items,
            'page' => $page,
            'per_page' => self::PAGE_SIZE,
            'total' => $total,
            'has_next' => $page * self::PAGE_SIZE < $total,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function page(string $raw): int
    {
        if (
            preg_match('/\A[1-9][0-9]{0,3}\z/D', $raw) !== 1
            || (int) $raw > self::MAX_PAGE
        ) {
            throw new BadRequestHttpException('Página inválida.');
        }

        return (int) $raw;
    }

    private function date(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $raw) !== 1) {
            throw new BadRequestHttpException('Fecha inválida.');
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $raw, new DateTimeZone('UTC'),
        );
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $raw) {
            throw new BadRequestHttpException('Fecha inválida.');
        }

        return $raw;
    }

    /** Only canonical opaque ULIDs may be shown; entity_id is otherwise untrusted. */
    private static function publicEntityId(string $raw): string
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $raw) === 1
            ? $raw : 'oculto';
    }

    /** @return array<string, bool|int|string|null> */
    private static function publicSnapshot(mixed $snapshot): array
    {
        if (!is_array($snapshot)) {
            return [];
        }
        $safe = [];
        foreach (self::PUBLIC_FIELDS as $field) {
            $value = $snapshot[$field] ?? null;
            if (is_bool($value) || is_int($value) || is_string($value)) {
                // Keep identifiers and enum-like values, never free text or PII.
                if (is_string($value) && (
                    strlen($value) > 64
                    || preg_match('/\A[a-zA-Z0-9._-]+\z/D', $value) !== 1
                )) {
                    continue;
                }
                $safe[$field] = $value;
            }
        }

        return $safe;
    }
}

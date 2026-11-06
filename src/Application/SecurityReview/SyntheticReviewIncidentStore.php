<?php

declare(strict_types=1);

namespace App\Application\SecurityReview;

use App\Domain\SecurityReview\ReviewState;
use DomainException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * DEV/test-only session storage. All records are fixture-labelled, scoped to
 * tenant AND branch, bounded and never written to a database or external host.
 */
final class SyntheticReviewIncidentStore
{
    /** @return list<array<string, string>> */
    public function items(SessionInterface $session, string $tenantId, string $branchId): array
    {
        return $this->bucket($session, $tenantId, $branchId)['items'];
    }

    /** @return list<array<string, string>> */
    public function audit(SessionInterface $session, string $tenantId, string $branchId): array
    {
        return $this->bucket($session, $tenantId, $branchId)['audit'];
    }

    /** @return array<string, string> */
    public function create(
        SessionInterface $session,
        string $tenantId,
        string $branchId,
        string $actorId,
        string $kind,
    ): array {
        $status = ReviewState::initial($kind);
        $bucket = $this->bucket($session, $tenantId, $branchId);
        if (count($bucket['items']) >= 30) {
            throw new DomainException('Límite de simulaciones alcanzado.');
        }

        $record = [
            'id' => 'fixture:'.bin2hex(random_bytes(8)),
            'kind' => $kind,
            'status' => $status,
            'label' => 'fixture:m14:'.$kind,
            'created_at' => gmdate(DATE_ATOM),
        ];
        $bucket['items'][] = $record;
        $this->record($bucket, $record, $actorId, 'created');
        $session->set($this->key($tenantId, $branchId), $bucket);

        return $record;
    }

    /** @return array<string, string> */
    public function transition(
        SessionInterface $session,
        string $tenantId,
        string $branchId,
        string $actorId,
        string $id,
        string $status,
    ): array {
        $bucket = $this->bucket($session, $tenantId, $branchId);
        foreach ($bucket['items'] as $index => $record) {
            if ($record['id'] !== $id) {
                continue;
            }

            ReviewState::assertTransition($record['kind'], $record['status'], $status);
            $record['status'] = $status;
            $bucket['items'][$index] = $record;
            $this->record($bucket, $record, $actorId, 'transitioned');
            $session->set($this->key($tenantId, $branchId), $bucket);

            return $record;
        }

        throw new DomainException('Simulación no encontrada en el ámbito solicitado.');
    }

    /** @return array{items: list<array<string,string>>, audit: list<array<string,string>>} */
    private function bucket(SessionInterface $session, string $tenantId, string $branchId): array
    {
        $data = $session->get($this->key($tenantId, $branchId), []);
        if (!is_array($data) || !isset($data['items'], $data['audit'])
            || !is_array($data['items']) || !is_array($data['audit'])) {
            return ['items' => [], 'audit' => []];
        }

        /** @var array{items: list<array<string,string>>, audit: list<array<string,string>>} $data */
        return $data;
    }

    private function key(string $tenantId, string $branchId): string
    {
        return 'm14_fixture_'.hash('sha256', $tenantId.'|'.$branchId);
    }

    /**
     * @param array{items: list<array<string,string>>, audit: list<array<string,string>>} $bucket
     * @param array<string,string> $record
     */
    private function record(array &$bucket, array $record, string $actorId, string $event): void
    {
        $bucket['audit'][] = [
            'item_id' => $record['id'],
            'kind' => $record['kind'],
            'status' => $record['status'],
            'event' => $event,
            'actor_ref' => 'fixture:'.substr(hash('sha256', $actorId), 0, 16),
            'at' => gmdate(DATE_ATOM),
        ];
        $bucket['audit'] = array_slice($bucket['audit'], -90);
    }
}

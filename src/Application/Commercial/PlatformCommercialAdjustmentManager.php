<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionChangeRecord;
use App\Domain\Commercial\SubscriptionChange;
use App\Domain\Commercial\SubscriptionLifecycle;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlatformCommercialAdjustmentManager
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<array-key,mixed> $addOnIds
     * @return array<string,mixed>
     */
    public function apply(
        string $tenantId,
        string $targetPlanVersionId,
        array $addOnIds,
        ?string $renewsAt,
        string $reason,
        string $requestedBy,
    ): array {
        $tenantId = trim($tenantId);
        $targetPlanVersionId = trim($targetPlanVersionId);
        $reason = trim($reason);
        $requestedBy = trim($requestedBy);
        if (
            $tenantId === ''
            || $targetPlanVersionId === ''
            || $reason === ''
            || mb_strlen($reason, 'UTF-8') > 500
            || strlen($requestedBy) !== 26
        ) {
            throw new DomainException('Ajuste comercial incompleto.');
        }

        $subscription = $this->entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);
        if (!$subscription instanceof Subscription) {
            throw new DomainException(
                'La empresa no tiene una suscripción comercial configurada.',
            );
        }

        $target = $this->entityManager
            ->getRepository(PlanVersion::class)
            ->find($targetPlanVersionId);
        if (!$target instanceof PlanVersion) {
            throw new DomainException('PlanVersion comercial inexistente.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$target->plan()->isActive() || !$target->isEffectiveAt($now)) {
            throw new DomainException('PlanVersion comercial no vigente.');
        }

        $addOns = $this->resolveAddOns($target, $addOnIds);
        if ($renewsAt === null) {
            $change = SubscriptionChange::upgrade(
                $tenantId,
                $subscription->planVersion(),
                $target,
                $now,
                $addOns,
            );
        } else {
            $change = SubscriptionChange::downgrade(
                $tenantId,
                $subscription->planVersion(),
                $target,
                $now,
                SubscriptionLifecycle::parseHistoricalTime($renewsAt),
                true,
                $addOns,
            );
        }

        $record = SubscriptionChangeRecord::fromManualAdjustment(
            $change,
            $requestedBy,
            $reason,
        );
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $this->payload($record, $change, $target, $addOns);
    }

    /**
     * @param array<array-key,mixed> $ids
     * @return list<AddOn>
     */
    private function resolveAddOns(PlanVersion $target, array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > 50) {
            throw new DomainException('Selección de add-ons inválida.');
        }

        $allowed = [];
        foreach ($target->addOns() as $addOn) {
            if ($addOn->isActive()) {
                $allowed[$addOn->id()] = $addOn;
            }
        }

        $selected = [];
        foreach ($ids as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new DomainException('Add-on inválido.');
            }
            $id = trim($id);
            $addOn = $allowed[$id] ?? null;
            if (!$addOn instanceof AddOn) {
                throw new DomainException(
                    'Add-on incompatible o inactivo para la PlanVersion.',
                );
            }
            if (isset($selected[$id])) {
                throw new DomainException('Add-on duplicado.');
            }
            $selected[$id] = $addOn;
        }

        uasort(
            $selected,
            static fn (AddOn $left, AddOn $right): int =>
                $left->key() <=> $right->key(),
        );

        return array_values($selected);
    }

    /**
     * @param list<AddOn> $addOns
     * @return array<string,mixed>
     */
    private function payload(
        SubscriptionChangeRecord $record,
        SubscriptionChange $change,
        PlanVersion $target,
        array $addOns,
    ): array {
        $audit = $record->audit();
        if ($audit === null) {
            throw new DomainException('Ajuste comercial sin auditoría.');
        }

        return [
            'id' => $record->id(),
            'direction' => $change->direction(),
            'status' => $change->status(),
            'current_plan' => self::plan($change->currentPlan()),
            'target_plan' => self::plan($target),
            'add_ons' => array_map(
                static fn (AddOn $addOn): array => [
                    'id' => $addOn->id(),
                    'key' => $addOn->key(),
                    'name' => $addOn->name(),
                    'monthly_amount' => $addOn->monthlyAmount(),
                    'quote_required' => $addOn->quoteRequired(),
                ],
                $addOns,
            ),
            'price' => [
                'currency' => $target->currency(),
                'monthly_amount' => $target->monthlyAmount(),
                'annual_amount' => $target->annualAmount(),
                'quote_required' => $target->quoteRequired(),
            ],
            'requested_at' => self::time($change->requestedAt()),
            'effective_at' => $change->effectiveAt() instanceof DateTimeImmutable
                ? self::time($change->effectiveAt())
                : null,
            'audit' => $audit,
        ];
    }

    /** @return array{key:string,name:string,version:int} */
    private static function plan(PlanVersion $version): array
    {
        return [
            'key' => $version->plan()->key(),
            'name' => $version->plan()->name(),
            'version' => $version->version(),
        ];
    }

    private static function time(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }
}

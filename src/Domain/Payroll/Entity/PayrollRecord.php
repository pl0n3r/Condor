<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Entity;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * F4 synthetic-only payroll row. There is deliberately no approval, payment
 * or real-data activation path; only an immutable proposal for human review.
 */
final readonly class PayrollRecord
{
    public function __construct(
        private string $tenantRef,
        private string $legalEntityRef,
        private string $recordRef,
        private string $employeeRef,
        private string $periodRef,
        private string $actorRef,
        private int $amountHundredths,
        private DateTimeImmutable $createdAt,
    ) {
        self::fixtureRef($tenantRef, 'tenant');
        self::fixtureRef($legalEntityRef, 'entity');
        self::fixtureRef($recordRef, 'payroll');
        self::fixtureRef($employeeRef, 'employee');
        self::fixtureRef($periodRef, 'period');
        self::fixtureRef($actorRef, 'actor');
        if ($amountHundredths < 0 || $amountHundredths > 99999999999) {
            throw new DomainException('Synthetic payroll amount out of bounds.');
        }
    }

    public static function fixtureRef(mixed $value, string $kind): string
    {
        $pattern = '~^fixture:' . preg_quote($kind, '~') . '/[a-z0-9][a-z0-9_-]{0,55}$~D';
        if (!is_string($value) || strlen($value) > 96 || preg_match($pattern, $value) !== 1) {
            throw new DomainException('Only synthetic fixture references are accepted.');
        }

        return $value;
    }

    public function tenantRef(): string { return $this->tenantRef; }
    public function legalEntityRef(): string { return $this->legalEntityRef; }
    public function recordRef(): string { return $this->recordRef; }
    public function actorRef(): string { return $this->actorRef; }

    /**
     * @return array{tenant_ref:string,legal_entity_ref:string,record_ref:string,
     *     employee_ref:string,period_ref:string,actor_ref:string,
     *     amount_hundredths:int,state:string,created_at:string,fingerprint:string}
     */
    public function snapshot(): array
    {
        $fields = [
            'tenant_ref' => $this->tenantRef,
            'legal_entity_ref' => $this->legalEntityRef,
            'record_ref' => $this->recordRef,
            'employee_ref' => $this->employeeRef,
            'period_ref' => $this->periodRef,
            'actor_ref' => $this->actorRef,
            'amount_hundredths' => $this->amountHundredths,
            'state' => 'REQUIERE_REVISION',
            'created_at' => $this->createdAt
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];

        return [
            ...$fields,
            'fingerprint' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
        ];
    }
}

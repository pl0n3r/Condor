<?php

declare(strict_types=1);

namespace App\Application\Payments;

/**
 * Provider-neutral, sandbox-only contract. Amounts are integer minor units;
 * never accept card or customer PII, or infer a production payment from a result.
 *
 * The associative inputs/outputs are immutable value payloads with explicit
 * shapes. Adapters must validate them, not trust request-side tenant settings.
 */
interface PaymentGateway
{
    public function id(): string;

    public function mode(): string;

    /**
     * @param array{tenant_id:string,company_id:string,order_ref:string,amount_minor:int,currency:string,idempotency_key:string,scenario?:string} $request
     * @return array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null}
     */
    public function charge(array $request): array;

    /** @return array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null} */
    public function query(string $tenantId, string $companyId, string $chargeRef): array;

    /**
     * @param array{tenant_id:string,company_id:string,charge_ref:string,amount_minor:int,idempotency_key:string} $request
     * @return array{charge_ref:string,status:string,refunded_minor:int,refund_ref:string}
     */
    public function refund(array $request): array;

    /** A false result is never authorization to update the ledger. */
    public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool;
}

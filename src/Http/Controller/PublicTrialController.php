<?php
declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\TrialAndPlanChangeService;
use DomainException;

/**
 * Offline-only adapter for configurator self-service proposals.
 * Deliberately no HTTP Route attribute: production activation is out of scope.
 */
final class PublicTrialController
{
    /**
     * Requires tenant, subscription and quote evidence prepared on the server
     * from canonical SubscriptionLifecycle and PlanQuoteService::preview.
     * @param array<string,mixed> $serverContext
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function prepareSyntheticRequest(array $serverContext, array $payload): array
    {
        $keys = array_keys($serverContext);
        sort($keys);
        if ($keys !== [
            'actor_ref', 'actor_role', 'catalog_quote', 'company_ref',
            'permission', 'subscription', 'tenant_ref',
        ]) {
            throw new DomainException('Contexto de servidor incompleto.');
        }
        if (array_keys($payload) !== ['request']) {
            throw new DomainException('Solicitud comercial inválida.');
        }
        return TrialAndPlanChangeService::evaluate(
            $serverContext + ['request' => $payload['request']],
        );
    }
}

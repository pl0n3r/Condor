<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commerce\EffectivePriceExplainer;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\SalesChannel;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class EffectivePriceController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly EffectivePriceExplainer $explainer,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/effective/explain',
        name: 'api_effective_price_explanation',
        methods: ['GET'],
    )]
    public function explain(string $branchId, Request $request): JsonResponse
    {
        // Reject unknown parameters, arrays, and unbounded input before DB
        // queries. Branch authorization always determines the tenant.
        $query = $request->query->all();
        if (array_diff(array_keys($query), ['variant', 'channel', 'quantity', 'at']) !== []) {
            throw new UnprocessableEntityHttpException('Parámetros no permitidos.');
        }
        foreach ($query as $value) {
            if (!is_string($value)) {
                throw new UnprocessableEntityHttpException('Los parámetros deben ser texto.');
            }
        }
        [, $tenant] = $this->authorizedBranchScope($branchId, 'pricing.view');

        $variantId = trim($query['variant'] ?? '');
        $channelId = trim($query['channel'] ?? '');
        if ($variantId === '' || $channelId === '' || strlen($variantId) > 80 || strlen($channelId) > 80) {
            throw new UnprocessableEntityHttpException('Debes indicar variante y canal válidos.');
        }
        $quantityText = $query['quantity'] ?? '1';
        if (
            $quantityText === ''
            || strlen($quantityText) > 4
            || !ctype_digit($quantityText)
            || (int) $quantityText < 1
            || (int) $quantityText > 1000
        ) {
            throw new UnprocessableEntityHttpException('Cantidad fuera de rango (1..1000).');
        }

        $at = $this->at($query['at'] ?? null);
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $variantId,
            'tenant' => $tenant,
            'active' => true,
        ]);
        if (
            !$variant instanceof ProductVariant
            || !$variant->product()->isActive()
        ) {
            throw new NotFoundHttpException('Variante no encontrada.');
        }
        $channel = $this->entityManager->getRepository(SalesChannel::class)->findOneBy([
            'id' => $channelId,
            'tenant' => $tenant,
            'active' => true,
        ]);
        if (!$channel instanceof SalesChannel || !$channel->isPublishable()) {
            throw new NotFoundHttpException('Canal no encontrado.');
        }

        $result = $this->domain(fn (): array => $this->explainer->explain(
            $tenant,
            $variant,
            $channel,
            (int) $quantityText,
            $at,
        ));

        return $this->json(['effective_price' => $result]);
    }

    private function at(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        if (
            strlen($raw) > 40
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                $raw,
            ) !== 1
        ) {
            throw new UnprocessableEntityHttpException('La fecha debe ser ISO 8601 con zona horaria.');
        }
        try {
            $at = new DateTimeImmutable($raw);
        } catch (Exception $exception) {
            throw new UnprocessableEntityHttpException('Fecha inválida.', $exception);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new UnprocessableEntityHttpException('Fecha inválida.');
        }

        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}

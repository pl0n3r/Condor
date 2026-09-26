<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

trait OrderItemsApiSupport
{
    /**
     * @return list<array{variant: ProductVariant, quantity: int}>
     */
    private function orderItems(
        EntityManagerInterface $entityManager,
        Tenant $tenant,
        mixed $value,
        string $context,
    ): array {
        if (!is_array($value) || $value === [] || count($value) > 100) {
            throw new UnprocessableEntityHttpException(sprintf(
                'El %s debe contener entre 1 y 100 líneas.',
                $context,
            ));
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_object($item)) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Cada línea del %s debe ser un objeto.',
                    $context,
                ));
            }

            $data = get_object_vars($item);
            if (
                array_diff(array_keys($data), ['variant_id', 'quantity']) !== []
                || !is_string($data['variant_id'] ?? null)
                || !is_int($data['quantity'] ?? null)
            ) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Las líneas del %s contienen campos inválidos.',
                    $context,
                ));
            }

            $variant = $entityManager->getRepository(ProductVariant::class)
                ->findOneBy([
                    'id' => $data['variant_id'],
                    'tenant' => $tenant,
                    'active' => true,
                ]);
            if (
                !$variant instanceof ProductVariant
                || !$variant->product()->isActive()
            ) {
                throw new NotFoundHttpException(sprintf(
                    'Variante del %s no encontrada.',
                    $context,
                ));
            }

            $items[] = [
                'variant' => $variant,
                'quantity' => $data['quantity'],
            ];
        }

        return $items;
    }
}

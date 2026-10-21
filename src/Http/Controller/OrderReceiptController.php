<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\Branch;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Read-only printable view. Never trust an ID before resolving the active tenant. */
final class OrderReceiptController extends AbstractController
{
    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/orders/{id}/receipt', name: 'api_order_receipt', methods: ['GET'])]
    public function show(string $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Debes iniciar sesión.');
        }

        try {
            $tenant = $this->currentTenantForUser->resolve($user);
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException('Acceso denegado.', $exception);
        }

        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'id' => $id,
            'tenant' => $tenant,
        ]);
        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }

        $source = $order->inventorySource();
        $branch = $source->branch();
        if ($branch instanceof Branch) {
            if ($branch->legalEntity()?->id() !== $order->legalEntity()->id()) {
                throw new NotFoundHttpException('Pedido no encontrado.');
            }
            try {
                $this->authorization->require($user, $tenant, $branch, 'orders.view');
            } catch (AccessDeniedException $exception) {
                throw new AccessDeniedHttpException('Acceso denegado.', $exception);
            }
        } else {
            // A logical source has no branch grant. Only the tenant owner may see it.
            try {
                $membership = $this->authorization->membership($user, $tenant);
            } catch (AccessDeniedException $exception) {
                throw new AccessDeniedHttpException('Acceso denegado.', $exception);
            }
            if (!$this->authorization->isOwner($membership)) {
                throw new NotFoundHttpException('Pedido no encontrado.');
            }
        }

        $lines = $this->entityManager->getRepository(OrderLine::class)->findBy(
            ['order' => $order, 'tenant' => $tenant],
            ['id' => 'ASC'],
        );
        $total = 0;
        $items = [];
        foreach ($lines as $line) {
            if (!$line instanceof OrderLine || $line->currency() !== $order->currency()) {
                throw new ConflictHttpException('Los importes del pedido no son consistentes.');
            }
            $amount = $line->lineTotalMinor();
            if ($amount < 0 || $amount > 9007199254740991 - $total) {
                throw new ConflictHttpException('Los importes del pedido no son consistentes.');
            }
            $total += $amount;
            $items[] = [
                'name' => $line->variant()->product()->name().' · '.$line->variant()->name(),
                'sku' => $line->variant()->sku(),
                'quantity' => $line->quantity(),
                'unit' => self::money($line->effectiveAmountMinor()),
                'subtotal' => self::money($amount),
            ];
        }
        if ($total !== $order->totalAmountMinor()) {
            throw new ConflictHttpException('Los importes del pedido no son consistentes.');
        }

        $response = $this->render('orders/receipt.html.twig', [
            'receipt' => [
                'id' => $order->id(),
                'company' => $order->legalEntity()->legalName(),
                'nit' => $order->legalEntity()->nit(),
                'branch' => $branch?->name() ?? $source->name(),
                'customer' => $order->customer()?->name() ?? 'Consumidor final',
                'date' => $order->createdAt()
                    ->setTimezone(new DateTimeZone('America/Bogota'))
                    ->format('d/m/Y H:i'),
                'currency' => $order->currency(),
                'status' => $order->orderStatus(),
                'items' => $items,
                'total' => self::money($total),
            ],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'none'",
        );

        return $response;
    }

    private static function money(int $minor): string
    {
        return number_format(intdiv($minor, 100), 0, ',', '.')
            .','.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}

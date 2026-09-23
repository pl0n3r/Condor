<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Storefront\StorefrontPresentation;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class StorefrontAdminController extends AbstractController
{
    #[Route('/admin/storefront', name: 'app_storefront_admin', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        CurrentTenantForUser $currentTenantForUser,
        BranchAuthorization $authorization,
        EntityManagerInterface $entityManager,
        StorefrontPresentation $storefront,
        AppVersion $version,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            throw new AccessDeniedHttpException();
        }

        try {
            $tenant = $currentTenantForUser->resolve($user);
            $membership = $authorization->membership($user, $tenant);
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException(
                'No tienes acceso a esta empresa.',
                $exception,
            );
        }

        $owner = $authorization->isOwner($membership);
        $canEdit = $owner;
        $canView = $owner || $authorization->hasAnyPermission(
            $user,
            $tenant,
            ['site.view', 'site.update'],
        );

        if (!$canView) {
            throw new AccessDeniedHttpException(
                'No tienes permiso para consultar este sitio.',
            );
        }

        $profile = $entityManager->getRepository(StorefrontProfile::class)
            ->findOneBy(['tenant' => $tenant]);
        $channel = $entityManager->getRepository(SalesChannel::class)
            ->findOneBy([
                'tenant' => $tenant,
                'type' => SalesChannel::TYPE_ECOMMERCE,
            ]);
        $sources = $this->availableSources(
            $entityManager,
            $authorization,
            $tenant,
            $user,
            $membership,
        );
        $priceLists = array_values(array_filter(
            $entityManager->getRepository(PriceList::class)->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            ),
            static fn (mixed $list): bool => $list instanceof PriceList,
        ));
        $identity = $storefront->publicIdentity($tenant);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$canEdit) {
                throw new AccessDeniedHttpException(
                    'No tienes permiso para editar este sitio.',
                );
            }

            $action = (string) $request->request->get('_action', 'profile');
            if ($action === 'channel') {
                $errors = $this->saveChannel(
                    $request,
                    $entityManager,
                    $tenant,
                    $user,
                    $channel,
                    $sources,
                    $priceLists,
                );
                if ($errors === []) {
                    return $this->redirectToRoute(
                        'app_storefront_admin',
                        ['saved' => 'channel'],
                    );
                }
            } elseif ($action === 'profile') {
                $errors = $this->saveProfile(
                    $request,
                    $entityManager,
                    $tenant,
                    $profile,
                    $identity,
                );
                if ($errors === []) {
                    return $this->redirectToRoute(
                        'app_storefront_admin',
                        ['saved' => 'profile'],
                    );
                }
            } else {
                $errors[] = 'La acción solicitada no es válida.';
            }
        }

        $channel = $entityManager->getRepository(SalesChannel::class)
            ->findOneBy([
                'tenant' => $tenant,
                'type' => SalesChannel::TYPE_ECOMMERCE,
            ]);

        return $this->render('tenant/manage.html.twig', [
            'app_version' => $version->human(),
            'storefront' => $identity,
            'domains' => $storefront->registeredDomains($tenant),
            'can_edit' => $canEdit,
            'channel' => $this->channelPayload($channel),
            'channel_sources' => array_map(
                static fn (InventorySource $source): array => [
                    'id' => $source->id(),
                    'name' => $source->name(),
                    'legal_entity' => $source->legalEntity()->legalName(),
                ],
                $sources,
            ),
            'price_lists' => array_map(
                static fn (PriceList $list): array => [
                    'id' => $list->id(),
                    'name' => $list->name(),
                    'currency' => $list->currency(),
                ],
                $priceLists,
            ),
            'saved' => (string) $request->query->get('saved', ''),
            'errors' => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /**
     * @param array<string, mixed> $identity
     * @return list<string>
     */
    private function saveProfile(
        Request $request,
        EntityManagerInterface $entityManager,
        Tenant $tenant,
        ?StorefrontProfile $profile,
        array &$identity,
    ): array {
        if (!$this->isCsrfTokenValid(
            'storefront_profile',
            (string) $request->request->get('_token', ''),
        )) {
            throw new AccessDeniedHttpException(
                'La solicitud de edición es inválida.',
            );
        }

        $submitted = $request->request->all();
        $unexpected = array_diff(
            array_keys($submitted),
            ['_token', '_action', 'headline', 'description'],
        );
        if (
            $unexpected !== []
            || !is_string($submitted['headline'] ?? null)
            || !is_string($submitted['description'] ?? null)
        ) {
            return ['El formulario contiene campos inválidos.'];
        }

        $identity['headline'] = trim($submitted['headline']);
        $identity['description'] = trim($submitted['description']);
        try {
            if ($profile instanceof StorefrontProfile) {
                $profile->update(
                    $identity['headline'],
                    $identity['description'],
                );
            } else {
                $profile = new StorefrontProfile(
                    $tenant,
                    $identity['headline'],
                    $identity['description'],
                );
                $entityManager->persist($profile);
            }
            $entityManager->flush();
        } catch (DomainException) {
            return [
                'El titular admite hasta 120 caracteres '
                .'y la descripción hasta 500.',
            ];
        }

        return [];
    }

    /**
     * @param list<InventorySource> $sources
     * @param list<PriceList> $priceLists
     * @return list<string>
     */
    private function saveChannel(
        Request $request,
        EntityManagerInterface $entityManager,
        Tenant $tenant,
        User $user,
        ?SalesChannel $channel,
        array $sources,
        array $priceLists,
    ): array {
        if (!$this->isCsrfTokenValid(
            'storefront_channel',
            (string) $request->request->get('_token', ''),
        )) {
            throw new AccessDeniedHttpException(
                'La solicitud de configuración del canal es inválida.',
            );
        }

        $submitted = $request->request->all();
        $unexpected = array_diff(
            array_keys($submitted),
            [
                '_token',
                '_action',
                'name',
                'slug',
                'inventory_source_id',
                'price_list_id',
                'active',
            ],
        );
        foreach (
            ['name', 'slug', 'inventory_source_id', 'price_list_id']
            as $field
        ) {
            if (!is_string($submitted[$field] ?? null)) {
                return ['La configuración del canal está incompleta.'];
            }
        }
        if ($unexpected !== []) {
            return ['El formulario del canal contiene campos inválidos.'];
        }

        $source = $this->findById($sources, $submitted['inventory_source_id']);
        $priceList = $this->findById($priceLists, $submitted['price_list_id']);
        if (!$source instanceof InventorySource) {
            throw new AccessDeniedHttpException(
                'No tienes acceso a la fuente de inventario seleccionada.',
            );
        }
        if (!$priceList instanceof PriceList) {
            return ['La lista de precios seleccionada no está disponible.'];
        }

        $created = !$channel instanceof SalesChannel;
        try {
            if ($created) {
                $channel = new SalesChannel(
                    $tenant,
                    $submitted['name'],
                    $submitted['slug'],
                    $source,
                    $priceList,
                );
                $entityManager->persist($channel);
            } else {
                $channel->update(
                    $submitted['name'],
                    $submitted['slug'],
                    $source,
                    $priceList,
                );
            }

            if (($submitted['active'] ?? null) === '1') {
                $channel->activate();
            } else {
                $channel->deactivate();
            }

            $entityManager->persist(new AuditEvent(
                $tenant,
                $user->id(),
                $created
                    ? 'sales_channel.created'
                    : 'sales_channel.updated',
                SalesChannel::class,
                $channel->id(),
                [
                    'type' => $channel->type(),
                    'legal_entity_id' => $channel->legalEntity()->id(),
                    'inventory_source_id' => $channel->inventorySource()->id(),
                    'price_list_id' => $channel->priceList()->id(),
                    'active' => $channel->isActive(),
                ],
            ));
            $entityManager->flush();
        } catch (DomainException $exception) {
            return [$exception->getMessage()];
        }

        return [];
    }

    /** @return list<InventorySource> */
    private function availableSources(
        EntityManagerInterface $entityManager,
        BranchAuthorization $authorization,
        Tenant $tenant,
        User $user,
        Membership $membership,
    ): array {
        $owner = $authorization->isOwner($membership);
        $sources = $entityManager->getRepository(InventorySource::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            );

        return array_values(array_filter(
            $sources,
            static function (mixed $source) use (
                $owner,
                $authorization,
                $user,
                $tenant,
            ): bool {
                if (!$source instanceof InventorySource) {
                    return false;
                }
                if ($owner) {
                    return true;
                }

                $branch = $source->branch();

                return $branch !== null
                    && $authorization->canAccessBranch(
                        $user,
                        $tenant,
                        $branch,
                    );
            },
        ));
    }

    /**
     * @template T of object
     * @param list<T> $items
     * @return T|null
     */
    private function findById(array $items, string $id): ?object
    {
        foreach ($items as $item) {
            if (method_exists($item, 'id') && $item->id() === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function channelPayload(?SalesChannel $channel): ?array
    {
        if (!$channel instanceof SalesChannel) {
            return null;
        }

        return [
            'id' => $channel->id(),
            'name' => $channel->name(),
            'slug' => $channel->slug(),
            'type' => $channel->type(),
            'active' => $channel->isActive(),
            'publishable' => $channel->isPublishable(),
            'inventory_source_id' => $channel->inventorySource()->id(),
            'price_list_id' => $channel->priceList()->id(),
            'legal_entity' => $channel->legalEntity()->legalName(),
        ];
    }
}

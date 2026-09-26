<?php

declare(strict_types=1);

namespace App\Http\ControlBot;

use App\Application\Identity\ControlBotStaffManager;
use App\Application\Identity\PlatformStaffInvitationResult;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Infrastructure\Security\ControlBotRequestAuthenticator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ops')]
final readonly class ControlBotStaffController
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;
    private const RECENT_FAILED_LOGIN_WINDOW_SECONDS = 86400;

    public function __construct(
        private ControlBotRequestAuthenticator $authenticator,
        private ControlBotStaffManager $staffManager,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/summary', name: 'controlbot_ops_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $this->authenticator->authenticate($request);
        $active = 0;
        $suspended = 0;
        $invited = 0;
        foreach ($this->entityManager->getRepository(User::class)->findAll() as $user) {
            if (!$this->isOperationalStaff($user)) {
                continue;
            }
            match ($this->staffStatus($user)) {
                'active' => ++$active,
                'invited' => ++$invited,
                default => ++$suspended,
            };
        }
        $recentFailedLogins = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(id) FROM condor_functional_signal '
            .'WHERE type = :type AND created_at >= :since',
            [
                'type' => FunctionalSignal::LOGIN_FAILURE,
                'since' => gmdate(
                    'Y-m-d H:i:s',
                    time() - self::RECENT_FAILED_LOGIN_WINDOW_SECONDS,
                ),
            ],
        );
        $this->authenticator->audit(
            $request,
            'controlbot.summary.read',
            null,
            ['result' => 'success'],
        );

        return new JsonResponse([
            'active' => $active,
            'suspended' => $suspended,
            'invited' => $invited,
            'recent_failed_logins' => $recentFailedLogins,
        ]);
    }

    #[Route('/staff', name: 'controlbot_ops_staff_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $this->authenticator->authenticate($request);
        $query = trim((string) $request->query->get('q', ''));
        $queryLength = mb_strlen($query, 'UTF-8');
        if ($queryLength < 2 || $queryLength > 120) {
            throw new UnprocessableEntityHttpException(
                'q debe tener entre 2 y 120 caracteres.',
            );
        }
        $limitRaw = (string) $request->query->get(
            'limit',
            (string) self::DEFAULT_LIMIT,
        );
        if (!ctype_digit($limitRaw)) {
            throw new UnprocessableEntityHttpException('limit inválido.');
        }
        $limit = (int) $limitRaw;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new UnprocessableEntityHttpException('limit fuera de rango.');
        }
        $afterId = $this->decodeCursor(
            (string) $request->query->get('cursor', ''),
        );
        $needle = mb_strtolower($query, 'UTF-8');
        $users = array_values(array_filter(
            $this->entityManager->getRepository(User::class)->findAll(),
            function (User $user) use ($needle, $afterId): bool {
                if (!$this->isReadableStaff($user)) {
                    return false;
                }
                if ($afterId !== null && strcmp($user->id(), $afterId) <= 0) {
                    return false;
                }

                $haystack = mb_strtolower(
                    $user->displayName().' '.$user->email(),
                    'UTF-8',
                );

                return str_contains($haystack, $needle);
            },
        ));
        usort(
            $users,
            static fn (User $left, User $right): int => strcmp(
                $left->id(),
                $right->id(),
            ),
        );
        $page = array_slice($users, 0, $limit + 1);
        $hasMore = count($page) > $limit;
        if ($hasMore) {
            array_pop($page);
        }
        $rows = array_map(
            fn (User $user): array => $this->staffPayload($user),
            $page,
        );
        $nextCursor = $hasMore
            ? $this->encodeCursor($page[array_key_last($page)]->id())
            : null;

        $this->authenticator->audit($request, 'controlbot.staff.search', null, [
            'result' => 'success',
            'result_count' => count($rows),
            'limit' => $limit,
        ]);

        return new JsonResponse([
            'items' => $rows,
            'limit' => $limit,
            'next_cursor' => $nextCursor,
        ]);
    }

    #[Route('/staff', name: 'controlbot_ops_staff_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->authenticator->authenticate($request);
        $payload = $request->toArray();
        if (array_key_exists('password', $payload) || array_key_exists('password_hash', $payload)) {
            $this->authenticator->audit(
                $request,
                'controlbot.staff.invite_rejected',
                null,
                ['result' => 'rejected'],
            );
            throw new UnprocessableEntityHttpException('ControlBot nunca define contraseñas.');
        }

        $target = strtolower(trim((string) ($payload['email'] ?? '')));
        $preparedInvitation = null;
        $replay = null;

        try {
            $this->entityManager->wrapInTransaction(
                function () use (
                    $request,
                    $payload,
                    $target,
                    &$preparedInvitation,
                    &$replay,
                ): void {
                    $replay = $this->authenticator->beginIdempotentMutation(
                        $request,
                        'invite_staff',
                        $target,
                    );
                    if ($replay !== null) {
                        return;
                    }

                    $preparedInvitation = $this->staffManager->invite(
                        (string) ($payload['email'] ?? ''),
                        (string) ($payload['name'] ?? ''),
                        (string) ($payload['role'] ?? ''),
                    );
                    $this->authenticator->audit(
                        $request,
                        'controlbot.staff.invited',
                        $preparedInvitation->user->id(),
                        ['result' => 'success'],
                    );
                    $this->authenticator->completeIdempotentMutation(
                        $request,
                        'invite_staff',
                        $target,
                        Response::HTTP_ACCEPTED,
                        [
                            'state' => 'pending_delivery',
                            'user_id' => $preparedInvitation->user->id(),
                        ],
                    );
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            $this->authenticator->audit(
                $request,
                'controlbot.staff.invite_rejected',
                null,
                ['result' => 'rejected', 'reason' => 'duplicate_email'],
            );
            throw new ConflictHttpException(
                'Ya existe una cuenta con ese correo.',
                $exception,
            );
        } catch (DomainException $exception) {
            $this->authenticator->audit(
                $request,
                'controlbot.staff.invite_rejected',
                null,
                ['result' => 'rejected'],
            );
            if (str_contains($exception->getMessage(), 'entrega')) {
                throw new ServiceUnavailableHttpException(
                    null,
                    $exception->getMessage(),
                    $exception,
                );
            }
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }

        if (is_array($replay)) {
            // pending_delivery is deliberately replayed as-is. Once control
            // crossed the external mail boundary we cannot prove whether a
            // provider side effect happened before a crash/timeout. Reissuing
            // here would violate equivalent-retry semantics by potentially
            // sending a second invitation and invalidating the first token.
            return new JsonResponse($replay['payload'], $replay['status']);
        }
        if (!$preparedInvitation instanceof PlatformStaffInvitationResult) {
            throw new \LogicException('Invitación ControlBot no preparada.');
        }

        try {
            $this->staffManager->deliverInvitation($preparedInvitation);
        } catch (\Throwable $exception) {
            $cleanupPending = false;
            try {
                $this->entityManager->wrapInTransaction(
                    function () use ($request, $preparedInvitation): void {
                        $this->staffManager->revokeInvitation($preparedInvitation);
                        $this->authenticator->audit(
                            $request,
                            'controlbot.staff.invitation_delivery_failed',
                            $preparedInvitation->user->id(),
                            ['result' => 'failed'],
                        );
                    },
                );
            } catch (\Throwable) {
                // Cleanup is best-effort and must not leave the idempotency
                // record permanently pending. A later request with the same
                // key must replay the same terminal result, not redeliver.
                $cleanupPending = true;
            }

            $failurePayload = [
                'error' => 'INVITATION_DELIVERY_FAILED',
                'delivery_state' => 'failed_or_unknown',
                'cleanup_pending' => $cleanupPending,
            ];
            $this->authenticator->completeIdempotentMutation(
                $request,
                'invite_staff',
                $target,
                Response::HTTP_SERVICE_UNAVAILABLE,
                $failurePayload,
            );

            return new JsonResponse(
                $failurePayload,
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $responsePayload = [
            'id' => $preparedInvitation->user->id(),
            'status' => 'invited',
            'invitation_sent' => true,
        ];
        $this->authenticator->completeIdempotentMutation(
            $request,
            'invite_staff',
            $target,
            Response::HTTP_CREATED,
            $responsePayload,
        );

        return new JsonResponse($responsePayload, Response::HTTP_CREATED);
    }

    #[Route('/staff/{id}/suspend', name: 'controlbot_ops_staff_suspend', methods: ['POST'])]
    public function suspend(string $id, Request $request): JsonResponse
    {
        $staff = $this->mutableStaff($id, $request, 'suspend');

        return $this->idempotentMutation(
            $request,
            'suspend_staff',
            $id,
            function () use ($staff, $id, $request): JsonResponse {
                $reasonCode = trim(
                    (string) ($request->toArray()['reason_code'] ?? ''),
                );
                if (
                    $reasonCode === ''
                    || mb_strlen($reasonCode, 'UTF-8') > 120
                ) {
                    throw new UnprocessableEntityHttpException(
                        'reason_code es obligatorio y no puede superar 120 caracteres.',
                    );
                }
                $this->staffManager->suspend($staff);
                $this->authenticator->audit(
                    $request,
                    'controlbot.staff.suspended',
                    $id,
                    ['result' => 'success', 'reason_code' => $reasonCode],
                );

                return new JsonResponse([
                    'id' => $staff->id(),
                    'status' => 'suspended',
                ]);
            },
        );
    }

    #[Route('/staff/{id}/reactivate', name: 'controlbot_ops_staff_reactivate', methods: ['POST'])]
    public function reactivate(string $id, Request $request): JsonResponse
    {
        $staff = $this->mutableStaff($id, $request, 'reactivate');

        return $this->idempotentMutation(
            $request,
            'reactivate_staff',
            $id,
            function () use ($staff, $id, $request): JsonResponse {
                try {
                    $this->staffManager->reactivate($staff);
                } catch (DomainException $exception) {
                    $this->authenticator->audit(
                        $request,
                        'controlbot.staff.reactivate_rejected',
                        $id,
                        ['result' => 'rejected'],
                    );
                    throw new ConflictHttpException(
                        $exception->getMessage(),
                        $exception,
                    );
                }
                $this->authenticator->audit(
                    $request,
                    'controlbot.staff.reactivated',
                    $id,
                    ['result' => 'success'],
                );

                return new JsonResponse([
                    'id' => $staff->id(),
                    'status' => 'active',
                ]);
            },
        );
    }

    #[Route('/staff/{id}/role', name: 'controlbot_ops_staff_role', methods: ['POST'])]
    public function role(string $id, Request $request): JsonResponse
    {
        $staff = $this->mutableStaff($id, $request, 'role');
        try {
            return $this->idempotentMutation(
                $request,
                'change_staff_role',
                $id,
                function () use ($staff, $id, $request): JsonResponse {
                    $this->staffManager->setRole(
                        $staff,
                        (string) ($request->toArray()['role'] ?? ''),
                    );
                    $this->authenticator->audit(
                        $request,
                        'controlbot.staff.role_changed',
                        $id,
                        ['result' => 'success', 'role' => 'staff'],
                    );

                    return new JsonResponse([
                        'id' => $staff->id(),
                        'role' => 'staff',
                    ]);
                },
            );
        } catch (DomainException $exception) {
            $this->authenticator->audit($request, 'controlbot.staff.role_rejected', $id, ['result' => 'rejected']);
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
    }

    #[Route('/staff/{id}/password-reset', name: 'controlbot_ops_staff_password_reset', methods: ['POST'])]
    public function passwordReset(string $id, Request $request): JsonResponse
    {
        $staff = $this->mutableStaff($id, $request, 'password_reset');
        $this->authenticator->requireIdempotencyKey($request);
        $this->authenticator->audit($request, 'controlbot.staff.password_reset_blocked', $staff->id(), [
            'result' => 'blocked',
            'dependency' => 'Condor#191',
        ]);

        throw new ConflictHttpException('Recuperación de contraseña pendiente de integrar Condor #191.');
    }

    private function mutableStaff(string $id, Request $request, string $operation): User
    {
        $this->authenticator->authenticate($request);
        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user instanceof User
            || !$user->hasRole(User::ROLE_PLATFORM_STAFF)
            || $user->hasRole(User::ROLE_PLATFORM_OWNER)
            || $user->hasRole(User::ROLE_LEGACY_SUPER_ADMIN)) {
            $this->authenticator->audit($request, 'controlbot.staff.'.$operation.'_rejected', $id, [
                'result' => 'rejected',
            ]);
            throw new NotFoundHttpException('Staff no encontrado.');
        }

        return $user;
    }

    /**
     * @param callable(): JsonResponse $operation
     */
    private function idempotentMutation(
        Request $request,
        string $action,
        string $target,
        callable $operation,
    ): JsonResponse {
        return $this->entityManager->wrapInTransaction(
            function () use ($request, $action, $target, $operation): JsonResponse {
                $replay = $this->authenticator->beginIdempotentMutation(
                    $request,
                    $action,
                    $target,
                );
                if ($replay !== null) {
                    return new JsonResponse($replay['payload'], $replay['status']);
                }

                $response = $operation();
                $payload = json_decode(
                    (string) $response->getContent(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                if (!is_array($payload)) {
                    throw new \LogicException('Respuesta ControlBot no serializable.');
                }
                $this->authenticator->completeIdempotentMutation(
                    $request,
                    $action,
                    $target,
                    $response->getStatusCode(),
                    $payload,
                );

                return $response;
            },
        );
    }

    /** @return array<string, mixed> */
    private function staffPayload(User $user): array
    {
        return [
            'id' => $user->id(),
            'name' => $user->displayName(),
            'email_masked' => $this->maskEmail($user->email()),
            'role' => $this->staffRole($user),
            'status' => $this->staffStatus($user),
            'last_access_at' => $user->lastAccessAt()?->format(DATE_ATOM),
        ];
    }

    private function staffStatus(User $user): string
    {
        if ($user->hasRole(User::ROLE_PLATFORM_STAFF) && $user->getPassword() === '') {
            $invitation = $this->entityManager
                ->getRepository(AccountInvitation::class)
                ->findOneBy(['user' => $user]);
            if ($invitation instanceof AccountInvitation
                && $invitation->isUsableAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                return 'invited';
            }
        }

        return $user->isActive() ? 'active' : 'suspended';
    }

    private function isOperationalStaff(User $user): bool
    {
        return $user->hasRole(User::ROLE_PLATFORM_STAFF)
            && !$user->hasRole(User::ROLE_PLATFORM_OWNER)
            && !$user->hasRole(User::ROLE_LEGACY_SUPER_ADMIN);
    }

    private function isReadableStaff(User $user): bool
    {
        return $user->hasRole(User::ROLE_PLATFORM_OWNER)
            || $user->hasRole(User::ROLE_LEGACY_SUPER_ADMIN)
            || $user->hasRole(User::ROLE_PLATFORM_STAFF);
    }

    private function staffRole(User $user): string
    {
        if ($user->hasRole(User::ROLE_PLATFORM_OWNER)) {
            return 'owner';
        }
        if ($user->hasRole(User::ROLE_LEGACY_SUPER_ADMIN)) {
            return 'superadmin';
        }

        return 'staff';
    }

    private function encodeCursor(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): ?string
    {
        if ($cursor === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $cursor) !== 1) {
            throw new UnprocessableEntityHttpException('cursor inválido.');
        }
        $remainder = strlen($cursor) % 4;
        if ($remainder !== 0) {
            $cursor .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (
            !is_string($decoded)
            || preg_match('/^[A-Za-z0-9]{26}$/D', $decoded) !== 1
        ) {
            throw new UnprocessableEntityHttpException('cursor inválido.');
        }

        return $decoded;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1, 'UTF-8').'***@'.$domain;
    }
}

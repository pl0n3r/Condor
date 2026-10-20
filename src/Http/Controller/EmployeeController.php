<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\EmployeeService;
use App\Application\HumanResources\EmployeeStore;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Attendance\AttendanceAdapter;
use App\Domain\Attendance\AttendanceEventStore;
use App\Domain\Attendance\AttendanceService;
use App\Domain\HumanResources\Employee;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * An optional list port extending the offline #689 EmployeeStore.
 * A tenant-scoped persistent adapter is a separate, not-yet-approved leaf.
 */
interface EmployeeHttpStore extends EmployeeStore
{
    /** @return list<Employee> */
    public function listForTenant(Tenant $tenant): array;
}

/**
 * Fail-closed HR HTTP boundary: no implicit database, session/static store,
 * external reader, personal information or sensor-sensitive ingestion.
 */
final class EmployeeController extends AbstractController
{
    public function __construct(
        private readonly ?EmployeeHttpStore $employees = null,
        private readonly ?AttendanceEventStore $attendanceEvents = null,
        private readonly ?CurrentTenantForUser $currentTenantForUser = null,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    #[Route('/api/v1/hr/employees', name: 'api_hr_employees', methods: ['GET'])]
    public function index(): JsonResponse
    {
        [$tenant, $actor] = $this->scope();
        return $this->indexWithinTenant($tenant, $actor);
    }

    #[Route('/api/v1/hr/employees', name: 'api_hr_employees_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$tenant, $actor] = $this->scope();
        return $this->createWithinTenant($tenant, $actor, $request);
    }

    #[Route('/api/v1/hr/employees/{employeeId}', name: 'api_hr_employees_show', methods: ['GET'])]
    public function show(string $employeeId): JsonResponse
    {
        [$tenant, $actor] = $this->scope();
        return $this->showWithinTenant($tenant, $actor, $employeeId);
    }

    #[Route('/api/v1/hr/employees/{employeeId}/link', name: 'api_hr_employees_link', methods: ['POST'])]
    public function link(string $employeeId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$tenant, $actor] = $this->scope();
        // Authorize employee and tenant before resolving any user identifier.
        $this->ownedEmployee($tenant, $actor, $employeeId);
        $data = $this->strictPayload($request, ['user_id']);
        $id = $this->requiredString($data, 'user_id', 26);
        if ($this->entityManager === null) {
            throw new ServiceUnavailableHttpException(0, 'Almacén de usuarios no configurado.');
        }
        $target = $this->entityManager->getRepository(User::class)->find($id);
        if (!$target instanceof User
            || !$this->store()->membership($tenant, $target) instanceof Membership) {
            throw new NotFoundHttpException('Usuario no encontrado.');
        }
        return $this->linkWithinTenant($tenant, $actor, $employeeId, $target);
    }

    #[Route('/api/v1/hr/employees/{employeeId}/attendance', name: 'api_hr_employees_attendance', methods: ['POST'])]
    public function attendance(string $employeeId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$tenant, $actor] = $this->scope();
        return $this->attendanceWithinTenant($tenant, $actor, $employeeId, $request);
    }

    /** @return array{0: Tenant, 1: User} */
    private function scope(): array
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw new AccessDeniedHttpException('Autenticación requerida.');
        }
        if ($this->currentTenantForUser === null) {
            throw new ServiceUnavailableHttpException(0, 'Contexto de tenant no configurado.');
        }
        try {
            return [$this->currentTenantForUser->resolve($actor), $actor];
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException('Acceso denegado.', $exception);
        }
    }

    private function store(): EmployeeHttpStore
    {
        if (!$this->employees instanceof EmployeeHttpStore) {
            throw new ServiceUnavailableHttpException(0, 'Persistencia de empleados no habilitada.');
        }
        return $this->employees;
    }

    private function requireOwner(Tenant $tenant, User $actor): EmployeeHttpStore
    {
        $store = $this->store();
        $membership = $store->membership($tenant, $actor);
        if (!$actor->isActive() || !$membership instanceof Membership
            || !$membership->isActive()
            || $membership->tenant()->id() !== $tenant->id()
            || $membership->user()->id() !== $actor->id()
            || $membership->roleKey() !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('Solo el dueño del tenant puede administrar empleados.');
        }
        return $store;
    }

    private function indexWithinTenant(Tenant $tenant, User $actor): JsonResponse
    {
        $store = $this->requireOwner($tenant, $actor);
        $items = [];
        foreach ($store->listForTenant($tenant) as $employee) {
            if ($employee->tenant()->id() !== $tenant->id()) {
                throw new AccessDeniedHttpException('La consulta devolvió datos ajenos al tenant.');
            }
            $items[] = self::employeePayload($employee);
        }
        return $this->json(['employees' => $items]);
    }

    private function createWithinTenant(Tenant $tenant, User $actor, Request $request): JsonResponse
    {
        $store = $this->requireOwner($tenant, $actor);
        $data = $this->strictPayload($request, ['code']);
        $code = $this->requiredString($data, 'code', 32);
        try {
            $employee = (new EmployeeService($store))->create($tenant, $code, $actor);
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        return $this->json(['employee' => self::employeePayload($employee)], Response::HTTP_CREATED);
    }

    private function showWithinTenant(Tenant $tenant, User $actor, string $id): JsonResponse
    {
        return $this->json(['employee' => self::employeePayload($this->ownedEmployee($tenant, $actor, $id))]);
    }

    private function linkWithinTenant(Tenant $tenant, User $actor, string $id, User $target): JsonResponse
    {
        $store = $this->requireOwner($tenant, $actor);
        $this->ownedEmployee($tenant, $actor, $id);
        try {
            $updated = (new EmployeeService($store))->attachUser($tenant, $id, $target, $actor);
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        return $this->json(['employee' => self::employeePayload($updated)]);
    }

    private function attendanceWithinTenant(
        Tenant $tenant, User $actor, string $id, Request $request,
    ): JsonResponse {
        $employee = $this->ownedEmployee($tenant, $actor, $id);
        if (!$this->attendanceEvents instanceof AttendanceEventStore) {
            throw new ServiceUnavailableHttpException(0, 'Persistencia de asistencia no habilitada.');
        }
        $payload = $this->strictPayload($request, ['external_event_id', 'occurred_at', 'type']);
        // No source key or raw device/sensor-sensitive data is accepted from the client.
        $adapter = new class implements AttendanceAdapter {
            public function sourceKey(): string { return 'manual-http'; }
            public function normalizedMarks(): iterable { return []; }
        };
        try {
            $event = (new AttendanceService($this->attendanceEvents))->recordPayload(
                $tenant, $employee, $adapter, $payload,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        // First writes and valid replays both give an idempotent 200 receipt.
        return $this->json(['event' => [
            'id' => $event->id(), 'employee_id' => $event->employeeId(),
            'occurred_at' => $event->occurredAt(), 'type' => $event->type(),
        ]]);
    }

    private function ownedEmployee(Tenant $tenant, User $actor, string $id): Employee
    {
        $store = $this->requireOwner($tenant, $actor);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
            throw new NotFoundHttpException('Empleado no encontrado.');
        }
        $employee = (new EmployeeService($store))->find($tenant, $id, $actor);
        if (!$employee instanceof Employee || $employee->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Empleado no encontrado.');
        }
        return $employee;
    }

    /** @param list<string> $allowed
     *  @return array<string, mixed>
     */
    private function strictPayload(Request $request, array $allowed): array
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > 4096) {
            throw new BadRequestHttpException('JSON excede tamaño permitido.');
        }
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('JSON inválido.', $exception);
        }
        if (!is_object($decoded)) {
            throw new BadRequestHttpException('Se requiere un objeto JSON.');
        }
        $data = get_object_vars($decoded);
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new UnprocessableEntityHttpException('Campos no permitidos.');
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, int $max): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new UnprocessableEntityHttpException('Valor de '.$key.' inválido.');
        }
        return trim($value);
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('branch_access', (string) $request->headers->get('X-CSRF-Token', ''))) {
            throw new AccessDeniedHttpException('Token CSRF inválido.');
        }
    }

    /** @return array{id: string, code: string, linked: bool} */
    private static function employeePayload(Employee $employee): array
    {
        return ['id' => $employee->id(), 'code' => $employee->code(), 'linked' => $employee->user() !== null];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Domain\Identity\Entity\User;
use App\Infrastructure\Observability\ErrorIncidentPresenter;
use App\Infrastructure\Observability\ErrorIncidentRecorder;
use App\Infrastructure\Observability\ErrorIncidentSubscriber;
use App\Infrastructure\Observability\ErrorSanitizer;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ErrorIncidentSubscriberTest extends TestCase
{
    public function testPublicRequestNeverReceivesPrivilegedDiagnostic(): void
    {
        $payload = $this->payloadFor(null, false);

        self::assertArrayNotHasKey('diagnostic', $payload);
        self::assertSame('internal_error', $payload['error']);
    }

    public function testOrdinaryUserNeverReceivesPrivilegedDiagnostic(): void
    {
        $user = new User('ordinary@example.test', 'Usuario normal');

        $payload = $this->payloadFor($user, false);

        self::assertArrayNotHasKey('diagnostic', $payload);
    }

    public function testActivePlatformOwnerReceivesPrivilegedDiagnostic(): void
    {
        $owner = new User(
            'owner@example.test',
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );

        $payload = $this->payloadFor($owner, true);

        self::assertArrayHasKey('diagnostic', $payload);
        self::assertSame(500, $payload['diagnostic']['status']);
        self::assertSame(
            'app_platform_owner_context',
            $payload['diagnostic']['route'],
        );
    }

    public function testInactivePlatformOwnerFailsClosed(): void
    {
        $owner = new User(
            'inactive-owner@example.test',
            'Propietario inactivo',
            [User::ROLE_PLATFORM_OWNER],
        );
        $owner->deactivate();

        $payload = $this->payloadFor($owner, true);

        self::assertArrayNotHasKey('diagnostic', $payload);
    }

    public function testIdentityResolutionFailureFailsClosed(): void
    {
        $payload = $this->payloadFor(null, false, identityFailure: true);

        self::assertArrayNotHasKey('diagnostic', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(
        ?User $user,
        bool $granted,
        bool $identityFailure = false,
    ): array {
        $projectDir = dirname(__DIR__, 4);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);

        if ($identityFailure) {
            $tokenStorage
                ->expects(self::once())
                ->method('getToken')
                ->willThrowException(new RuntimeException('fallo de identidad'));
            $authorization
                ->expects(self::never())
                ->method('isGranted');
        } elseif ($user === null) {
            $tokenStorage
                ->expects(self::once())
                ->method('getToken')
                ->willReturn(null);
            $authorization
                ->expects(self::never())
                ->method('isGranted');
        } else {
            $token = $this->createMock(TokenInterface::class);
            $token
                ->method('getUser')
                ->willReturn($user);
            $tokenStorage
                ->expects(self::once())
                ->method('getToken')
                ->willReturn($token);

            if ($user->isActive()) {
                $authorization
                    ->expects(self::once())
                    ->method('isGranted')
                    ->with(User::ROLE_PLATFORM_OWNER)
                    ->willReturn($granted);
            } else {
                $authorization
                    ->expects(self::never())
                    ->method('isGranted');
            }
        }

        $subscriber = new ErrorIncidentSubscriber(
            new ErrorIncidentRecorder(
                $entityManager,
                new ErrorSanitizer($projectDir),
                new AppVersion($projectDir),
            ),
            new ErrorIncidentPresenter(),
            $tokenStorage,
            $authorization,
            'prod',
        );

        $request = Request::create('/adminpl0n3r/api/context', 'GET');
        $request->setRequestFormat('json');
        $request->attributes->set('_route', 'app_platform_owner_context');
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new RuntimeException('password=secreto'),
        );

        $subscriber->onException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('no-store', $response->headers->get('Cache-Control'));

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);

        return $payload;
    }
}

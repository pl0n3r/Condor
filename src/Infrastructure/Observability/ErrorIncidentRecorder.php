<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use App\Domain\Observability\Entity\ErrorIncident;
use App\Infrastructure\Http\RequestIdSubscriber;
use App\Shared\Id\UlidFactory;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class ErrorIncidentRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ErrorSanitizer $sanitizer,
        private AppVersion $version,
    ) {
    }

    public function record(
        Request $request,
        Throwable $error,
        int $status,
    ): ErrorIncident {
        $requestId = $request->attributes->get(RequestIdSubscriber::ATTRIBUTE);
        if (!is_string($requestId) || $requestId === '') {
            $requestId = UlidFactory::new();
        }

        $routeName = $request->attributes->get('_route');
        $routeName = is_string($routeName) && $routeName !== ''
            ? $routeName
            : null;

        $trace = $this->sanitizer->trace($error);
        $incident = new ErrorIncident(
            requestId: $requestId,
            status: $status,
            method: $request->getMethod(),
            routeName: $routeName,
            exceptionClass: $error::class,
            message: $this->sanitizer->message($error),
            fingerprint: $this->sanitizer->fingerprint($error, $routeName, $trace),
            version: $this->version->human(),
            releaseSha: $this->version->releaseSha(),
            trace: $trace,
        );

        try {
            $this->entityManager->persist($incident);
            $this->entityManager->flush();
        } catch (Throwable $storageError) {
            error_log(sprintf(
                'Condor error incident [%s] no pudo persistirse (%s).',
                $incident->id(),
                $storageError::class,
            ));
        }

        return $incident;
    }
}

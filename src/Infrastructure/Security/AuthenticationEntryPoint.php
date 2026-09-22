<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Http\ApiErrorResponseFactory;
use App\Infrastructure\Http\ApiRequest;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final readonly class AuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public function __construct(
        private ApiErrorResponseFactory $apiErrors,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function start(
        Request $request,
        ?AuthenticationException $authException = null,
    ): Response {
        if (ApiRequest::matches($request)) {
            return $this->apiErrors->create(
                $request,
                'unauthenticated',
                'Debes iniciar sesión para continuar.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($request->hasSession()) {
            $this->saveTargetPath(
                $request->getSession(),
                'main',
                $request->getUri(),
            );
        }

        return new RedirectResponse($this->urls->generate('app_login'));
    }
}

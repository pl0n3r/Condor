<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** @mixin WebTestCase */
trait BrowserCsrfToken
{
    private function csrfToken(
        KernelBrowser $client,
        string $tokenId,
    ): string {
        $request = $client->getRequest();
        self::assertNotNull($request);

        $container = static::getContainer();
        $requestStack = $container->get(RequestStack::class);
        $csrf = $container->get(CsrfTokenManagerInterface::class);
        $sessionFactory = $container->get('session.factory');

        self::assertInstanceOf(RequestStack::class, $requestStack);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrf);
        self::assertTrue(method_exists($sessionFactory, 'createSession'));

        $session = $sessionFactory->createSession();
        $existingCookie = $client->getCookieJar()->get(
            $session->getName(),
        );
        if ($existingCookie !== null) {
            $session->setId((string) $existingCookie->getValue());
        }

        $session->start();
        $request->setSession($session);

        $requestStack->push($request);
        try {
            $value = $csrf->getToken($tokenId)->getValue();
            $session->save();
        } finally {
            $requestStack->pop();
        }

        $client->getCookieJar()->set(
            new Cookie($session->getName(), $session->getId()),
        );

        return $value;
    }
}

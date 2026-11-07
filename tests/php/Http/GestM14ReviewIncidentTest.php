<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** S03/S04/S06: only fixture data, no real user/tenant or external providers. */
final class GestM14ReviewIncidentTest extends WebTestCase
{
    public function testSyntheticRequestsAreScopedAndRoleProtected(): void
    {
        $browser = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(6));
        $tenant = new Tenant('fixture alpha', 'fixture-alpha-'.$suffix);
        $foreign = new Tenant('fixture beta', 'fixture-beta-'.$suffix);
        $branch = new Branch($tenant, 'fixture branch', 'fixture', null, true);
        $foreignBranch = new Branch($foreign, 'fixture foreign', 'fixture', null, true);
        $owner = new User('fixture-owner-'.$suffix.'@example.test', 'Fixture Owner');
        $delegate = new User('fixture-reader-'.$suffix.'@example.test', 'Fixture Reader');
        $readerMembership = new Membership($tenant, $delegate, 'ADMIN');
        $role = new Role($tenant, 'fixture reader', ['roles.view']);
        foreach ([$tenant, $foreign, $branch, $foreignBranch, $owner, $delegate,
            new Membership($tenant, $owner, Membership::ROLE_OWNER), $readerMembership, $role] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new BranchRoleAssignment($readerMembership, $branch, $role));
        $em->flush();

        $path = '/api/v1/branches/'.$branch->id().'/m14/synthetic';
        $foreignPath = '/api/v1/branches/'.$foreignBranch->id().'/m14/synthetic';
        $browser->request('GET', $path);
        self::assertContains($browser->getResponse()->getStatusCode(), [302, 401, 403]);

        $browser->loginUser($owner);
        $token = $this->csrf($browser);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(200);
        $body = $this->body($browser);
        self::assertSame('synthetic', $body['mode']);
        self::assertSame([], $body['items']);

        $browser->jsonRequest('POST', $path, ['kind' => 'incident']);
        self::assertResponseStatusCodeSame(403);
        $browser->jsonRequest('POST', $path, ['kind' => 'incident'],
            ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(201);
        $item = $this->body($browser)['item'];
        self::assertSame('open', $item['status']);
        self::assertStringStartsWith('fixture:', $item['id']);

        $browser->jsonRequest('PATCH', $path.'/'.rawurlencode($item['id']),
            ['status' => 'resolved'], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(422);
        $browser->jsonRequest('PATCH', $path.'/'.rawurlencode($item['id']),
            ['status' => 'investigating'], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('investigating', $this->body($browser)['item']['status']);

        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->body($browser)['items']);
        self::assertCount(2, $this->body($browser)['audit']);
        $browser->request('GET', $foreignPath);
        self::assertResponseStatusCodeSame(404);
        $browser->jsonRequest('POST', $foreignPath, ['kind' => 'recertification'],
            ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(404);

        $browser->loginUser($delegate);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('synthetic', $this->body($browser)['mode']);
        $browser->jsonRequest('POST', $path, ['kind' => 'recertification'],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($browser)]);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string,mixed> */
    private function body(KernelBrowser $browser): array
    {
        $payload = json_decode((string) $browser->getResponse()->getContent(), true,
            512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function csrf(KernelBrowser $browser): string
    {
        $html = $browser->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $html->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }
}

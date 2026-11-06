<?php

declare(strict_types=1);

namespace App\Tests\Application\Governance;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** M15: tráfico HTTP Symfony autenticado, solo fixtures en BD descartable de test. */
final class GovernanceHttpIntegrationTest extends WebTestCase
{
    protected function tearDown(): void
    {
        putenv('CONDOR_GOVERNANCE_SYNTHETIC_API');
        parent::tearDown();
    }

    public function testFourKindsOverAuthenticatedSymfonyHttp(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$owner, $branch, $otherBranch, $foreignBranch] = self::fixture($em);
        $client->loginUser($owner);
        $csrf = self::csrf($client);
        $base = '/api/v1/branches/'.$branch->id().'/governance';

        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::payload($client)['records']);
        self::assertStringContainsString('no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'));

        $kinds = ['shareholder', 'faculty', 'authorization', 'decision'];
        foreach ($kinds as $kind) {
            $ref = 'fixture:'.$kind.'/demo';
            $client->jsonRequest('POST', $base, ['kind' => $kind, 'ref' => $ref],
                ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(201);
            self::assertSame(['ref' => $ref, 'kind' => $kind,
                'state' => 'REQUIERE_REVISION'], self::payload($client)['record']);

            $client->request('GET', $base.'/'.$kind.'/demo');
            self::assertResponseStatusCodeSame(200);
            self::assertSame($ref, self::payload($client)['record']['ref']);

            $client->jsonRequest('PATCH', $base.'/'.$kind.'/demo/state',
                ['state' => 'EN_REVISION'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(200);
            self::assertSame('EN_REVISION', self::payload($client)['record']['state']);
        }
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['authorization', 'decision', 'faculty', 'shareholder'],
            array_column(self::payload($client)['records'], 'kind'));

        $client->request('GET', '/api/v1/branches/'.$otherBranch->id().'/governance');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::payload($client)['records']);
        $client->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/governance');
        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongPermissionCsrfPayloadStateAndOffFlagFailClosed(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$owner, $branch, , , $staff] = self::fixture($em);
        $client->loginUser($owner);
        $base = '/api/v1/branches/'.$branch->id().'/governance';
        $payload = ['kind' => 'decision', 'ref' => 'fixture:decision/demo'];

        $client->jsonRequest('POST', $base, $payload);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $base, $payload, ['HTTP_X_CSRF_TOKEN' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $csrf = self::csrf($client);
        $client->jsonRequest('POST', $base, $payload + ['name' => 'Persona real'],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', $base,
            ['kind' => 'shareholder', 'ref' => 'persona@example.test'],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', $base, $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('PATCH', $base.'/decision/demo/state',
            ['state' => 'APROBADO'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('PATCH', $base.'/decision/demo/state',
            ['state' => 'EN_REVISION'], ['HTTP_X_CSRF_TOKEN' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, self::payload($client)['records']);
        self::assertSame('REQUIERE_REVISION', self::payload($client)['records'][0]['state']);

        putenv('CONDOR_GOVERNANCE_SYNTHETIC_API=0');
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $base, $payload,
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
        putenv('CONDOR_GOVERNANCE_SYNTHETIC_API');

        $client->loginUser($staff);
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $base, $payload,
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{User,Branch,Branch,Branch,User} */
    private static function fixture(EntityManagerInterface $em): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Gobierno A '.$suffix, 'governance-a-'.$suffix);
        $foreign = new Tenant('Gobierno B '.$suffix, 'governance-b-'.$suffix);
        $branch = new Branch($tenant, 'Principal', 'principal-'.$suffix, null, true);
        $other = new Branch($tenant, 'Secundaria', 'secundaria-'.$suffix);
        $foreignBranch = new Branch($foreign, 'Ajena', 'ajena-'.$suffix, null, true);
        $owner = new User('gov-owner-'.$suffix.'@example.test', 'Propietario');
        $staff = new User('gov-staff-'.$suffix.'@example.test', 'Colaborador');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $staffMembership = new Membership($tenant, $staff, 'STAFF');
        foreach ([$tenant, $foreign, $branch, $other, $foreignBranch, $owner,
            $staff, $ownerMembership, $staffMembership] as $record) {
            $em->persist($record);
        }
        $em->flush();
        return [$owner, $branch, $other, $foreignBranch, $staff];
    }

    private static function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $value = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($value);
        return $value;
    }

    /** @return array<string,mixed> */
    private static function payload(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}

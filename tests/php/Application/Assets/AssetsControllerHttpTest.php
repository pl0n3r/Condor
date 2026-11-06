<?php

declare(strict_types=1);

namespace App\Tests\Application\Assets;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** M10: real HTTP firewall, CSRF, tenant scope and synthetic-only data. */
final class AssetsControllerHttpTest extends WebTestCase
{
    public function testSyntheticHttpFlowAndDenials(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$branch, $owner] = $this->fixture($em);
        [$foreign] = $this->fixture($em);
        $base = '/api/v1/branches/'.$branch->id().'/assets';

        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($owner);
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        $body = $this->body($client);
        self::assertSame([], $body['assets']);
        self::assertNotEmpty($body['csrf_token']);
        $csrf = ['HTTP_X_CSRF_TOKEN' => $body['csrf_token']];

        $client->jsonRequest('POST', $base, ['asset_ref' => 'fixture:asset/demo']);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $base, ['asset_ref' => 'fixture:asset/demo'], $csrf);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('PENDIENTE', $this->body($client)['asset']['state']);

        $assign = $base.'/demo/responsible';
        $client->jsonRequest('PATCH', $assign, ['responsible_ref' => 'person@example.com'], $csrf);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('PATCH', $assign, ['responsible_ref' => 'fixture:responsible/demo', 'tenant' => 'other'], $csrf);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('PATCH', $assign, ['responsible_ref' => 'fixture:responsible/demo'], $csrf);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('ASIGNADO', $this->body($client)['asset']['state']);
        self::assertSame('fixture:responsible/demo', $this->body($client)['asset']['responsible_ref']);

        $state = $base.'/demo/state';
        foreach ([['state' => 'CERRADO'], ['state' => 'APROBADO'],
            ['state' => 'BAJA_REQUIERE_REVISION']] as $invalid) {
            $client->jsonRequest('PATCH', $state, $invalid, $csrf);
            self::assertResponseStatusCodeSame(422);
        }
        $client->jsonRequest('PATCH', $state, [
            'state' => 'BAJA_REQUIERE_REVISION', 'evidence_ref' => 'fixture:evidence/acta',
        ], $csrf);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('BAJA_REQUIERE_REVISION', $this->body($client)['asset']['state']);

        $client->request('GET', $base.'/demo');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('fixture:responsible/demo', $this->body($client)['asset']['responsible_ref']);
        $client->request('GET', '/api/v1/branches/'.$foreign->id().'/assets');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{Branch,User} */
    private function fixture(EntityManagerInterface $em): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('M10 '.$suffix, 'm10-'.$suffix);
        $company = new LegalEntity($tenant, 'Empresa sintética '.$suffix, null, true);
        $branch = new Branch($tenant, 'Sede sintética', 'm10-'.$suffix, $company, true);
        $owner = new User('m10-'.$suffix.'@example.test', 'Test fixture');
        foreach ([$tenant, $company, $branch, $owner,
            new Membership($tenant, $owner, Membership::ROLE_OWNER)] as $row) {
            $em->persist($row);
        }
        $em->flush();
        return [$branch, $owner];
    }

    /** @return array<string,mixed> */
    private function body(KernelBrowser $client): array
    {
        $json = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        return $json;
    }
}

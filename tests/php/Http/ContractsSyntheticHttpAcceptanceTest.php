<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** #1787: contratos M02, solo Symfony HTTP y datos inventados; ninguna firma legal. */
final class ContractsSyntheticHttpAcceptanceTest extends WebTestCase
{
    public function testOwnerIsolationAndManualReviewOverActualHttp(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = self::syntheticSuffix();

        $tenantA = new Tenant('Contratante A '.$suffix, 'contract-a-'.$suffix);
        $tenantB = new Tenant('Contratante B '.$suffix, 'contract-b-'.$suffix);
        $companyA = new LegalEntity($tenantA, 'Servicios A '.$suffix.' SAS', null, true);
        $companyA2 = new LegalEntity($tenantA, 'Consultoría A '.$suffix.' SAS', null);
        $companyB = new LegalEntity($tenantB, 'Servicios B '.$suffix.' SAS', null, true);
        $branchA = new Branch($tenantA, 'Principal A', 'principal', $companyA, true);
        $branchA2 = new Branch($tenantA, 'Secundaria A', 'secundaria', $companyA);
        $branchCompanyA2 = new Branch($tenantA, 'Principal otro NIT', 'otra-razon', $companyA2);
        $branchB = new Branch($tenantB, 'Principal B', 'principal', $companyB, true);
        $ownerA = new User('contratos-a-'.$suffix.'@example.test', 'Encargado sintético A');
        $ownerB = new User('contratos-b-'.$suffix.'@example.test', 'Encargado sintético B');
        foreach ([
            $tenantA, $tenantB, $companyA, $companyA2, $companyB,
            $branchA, $branchA2, $branchCompanyA2, $branchB,
            $ownerA, $ownerB,
            new Membership($tenantA, $ownerA, Membership::ROLE_OWNER),
            new Membership($tenantB, $ownerB, Membership::ROLE_OWNER),
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $baseA = '/api/v1/branches/'.$branchA->id().'/contracts';
        $client->request('GET', $baseA);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($ownerA);
        $csrf = self::csrf($client);

        $client->request('GET', $baseA);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::json($client)['records']);

        $client->jsonRequest('POST', $baseA, [
            'kind' => 'template', 'ref' => 'fixture:template/contrato_'.$suffix,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $template = self::json($client);
        self::assertSame('REQUIERE_REVISION', $template['record']['state']);
        self::assertTrue($template['synthetic_only']);

        $ref = 'fixture:contract/contrato_'.$suffix;
        $client->jsonRequest('POST', $baseA, [
            'kind' => 'contract', 'ref' => $ref,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($ref, self::json($client)['record']['ref']);
        $addendumRef = 'fixture:addendum/otrosi_'.$suffix;
        $client->jsonRequest('POST', $baseA, [
            'kind' => 'addendum', 'ref' => $addendumRef,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($addendumRef, self::json($client)['record']['ref']);

        // Dos sedes de la misma razón social y una segunda razón social
        // no comparten la sesión de registros, aunque el usuario sea owner.
        foreach ([$branchA2, $branchCompanyA2] as $isolated) {
            $client->request('GET', '/api/v1/branches/'.$isolated->id().'/contracts');
            self::assertResponseStatusCodeSame(200);
            self::assertSame([], self::json($client)['records']);
        }
        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/contracts');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString($ref, (string) $client->getResponse()->getContent());

        $path = $baseA.'/contract/contrato_'.$suffix;
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($ref, self::json($client)['record']['ref']);
        $client->jsonRequest('PATCH', $path.'/state', ['state' => 'EN_REVISION'],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('EN_REVISION', self::json($client)['record']['state']);

        // El preview no certifica firmas, vencimientos ni aprobaciones reales.
        $client->jsonRequest('PATCH', $path.'/state', ['state' => 'APROBADO'],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', $path);
        self::assertSame('EN_REVISION', self::json($client)['record']['state']);
    }

    public function testDelegatedAccessCsrfAndInvalidFieldsFailClosed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = self::syntheticSuffix();
        $tenant = new Tenant('Contratante delegado '.$suffix, 'contract-role-'.$suffix);
        $company = new LegalEntity($tenant, 'Empresa lectura '.$suffix.' SAS', null, true);
        $branch = new Branch($tenant, 'Sede lectura', 'lectura', $company, true);
        $user = new User('contratos-viewer-'.$suffix.'@example.test', 'Revisor sintético');
        $membership = new Membership($tenant, $user, 'ADMIN');
        $role = new Role($tenant, 'Lectura contratación '.$suffix, ['inventory.view']);
        $assignment = new BranchRoleAssignment($membership, $branch, $role);
        foreach ([$tenant, $company, $branch, $user, $membership, $role, $assignment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $base = '/api/v1/branches/'.$branch->id().'/contracts';
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::json($client)['records']);
        $csrf = self::csrf($client);
        $client->jsonRequest('POST', $base, [
            'kind' => 'contract', 'ref' => 'fixture:contract/demo',
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $base.'/contract/demo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerRejectsMissingCsrfExtraKeysAndNonSyntheticReferences(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = self::syntheticSuffix();
        $tenant = new Tenant('Contrato validación '.$suffix, 'contract-valid-'.$suffix);
        $company = new LegalEntity($tenant, 'Servicios validación '.$suffix.' SAS', null, true);
        $branch = new Branch($tenant, 'Sede', 'sede', $company, true);
        $owner = new User('contratos-valid-'.$suffix.'@example.test', 'Administrador sintético');
        foreach ([$tenant, $company, $branch, $owner,
            new Membership($tenant, $owner, Membership::ROLE_OWNER)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($owner);
        $base = '/api/v1/branches/'.$branch->id().'/contracts';
        $valid = ['kind' => 'contract', 'ref' => 'fixture:contract/demo_'.$suffix];

        $client->jsonRequest('POST', $base, $valid);
        self::assertResponseStatusCodeSame(403);
        $csrf = self::csrf($client);
        foreach ([
            $valid + ['worker_name' => 'Persona real'],
            ['kind' => 'contract', 'ref' => 'cedula:123456789'],
            ['kind' => 'contract', 'ref' => 'fixture:contract/123456789'],
            ['kind' => 'contract', 'ref' => 'fixture:template/demo'],
        ] as $invalid) {
            $client->jsonRequest('POST', $base, $invalid,
                ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('GET', $base);
        self::assertSame([], self::json($client)['records']);
    }

    /** Nunca genera una referencia con siete dígitos consecutivos (restricción del API). */
    private static function syntheticSuffix(): string
    {
        $hex = bin2hex(random_bytes(5));
        $suffix = substr($hex, 0, 5).'a'.substr($hex, 5);
        self::assertDoesNotMatchRegularExpression('/[0-9]{7,}/', $suffix);

        return $suffix;
    }

    /** Obtener el token real del admin para el contexto de sesión bajo prueba. */
    private static function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);
        return $token;
    }

    /** @return array<string, mixed> */
    private static function json(KernelBrowser $client): array
    {
        $result = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }
}

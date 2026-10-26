<?php

declare(strict_types=1);

namespace App\Tests\Http\Controller;

use App\Domain\Commerce\Entity\Customer;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerSearchControllerTest extends WebTestCase
{
    public function testAcceptance(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $tenant = new Tenant('Clientes A '.$suffix, 'clientes-a-'.$suffix);
        $branch = new Branch($tenant, 'Principal', 'principal-'.$suffix, null, true);
        $owner = new User('owner-'.$suffix.'@example.test', 'Owner');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $visible = new Customer(
            $tenant, 'Ángela García', 'angela-'.$suffix.'@example.test',
            '3001234567', 'Nota privada', documentNumber: 'CC-123.456',
        );
        $second = new Customer($tenant, 'Pedro Otro');
        $outsiderTenant = new Tenant('Clientes B '.$suffix, 'clientes-b-'.$suffix);
        $foreignBranch = new Branch($outsiderTenant, 'Externa', 'externa-'.$suffix, null, true);
        $foreign = new Customer($outsiderTenant, 'Ángela Externa');

        $staff = new User('staff-'.$suffix.'@example.test', 'Staff');
        $staffMembership = new Membership($tenant, $staff, 'STAFF');
        $noCustomersRole = new Role($tenant, 'Sin clientes '.$suffix, ['catalog.view']);
        $roleAssignment = new BranchRoleAssignment($staffMembership, $branch, $noCustomersRole);

        foreach ([
            $tenant, $branch, $owner, $ownerMembership, $visible, $second,
            $outsiderTenant, $foreignBranch, $foreign,
            $staff, $staffMembership, $noCustomersRole, $roleAssignment,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $url = '/api/v1/branches/'.$branch->id().'/customers/search';
        $client->request('GET', $url.'?term=angela');
        self::assertResponseStatusCodeSame(401);

        $client->loginUser($owner);
        $client->request('GET', $url.'?term=angela&segment=all');
        self::assertResponseStatusCodeSame(200);
        $content = (string) $client->getResponse()->getContent();
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$visible->id()], array_column($payload['customers'], 'id'));
        self::assertSame([
            'id', 'name', 'email', 'phone', 'notes', 'commercial_category_id',
        ], array_keys($payload['customers'][0]));
        self::assertSame('Ángela García', $payload['customers'][0]['name']);
        self::assertStringNotContainsString($foreign->id(), $content);
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        foreach (['new_this_month', 'without_purchases_90_days'] as $segment) {
            $client->request('GET', $url.'?segment='.$segment);
            self::assertResponseStatusCodeSame(200);
            self::assertCount(2, $this->jsonPayload($client)['customers']);
        }
        $client->request('GET', $url.'?segment=top_20_purchases');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->jsonPayload($client)['customers']);

        foreach ([
            '?segment=unknown', '?term[]=x', '?segment[]=all',
            '?extra=unexpected', '?term='.str_repeat('a', 121), '?term=%0A',
        ] as $invalid) {
            $client->request('GET', $url.$invalid);
            self::assertResponseStatusCodeSame(400);
        }
        $client->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/customers/search');
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($staff);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, mixed> */
    private function jsonPayload(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode(
            (string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR,
        );
    }
}

<?php
declare(strict_types=1);
namespace App\Tests\Http;
use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
final class OperationsProcessControllerTest extends WebTestCase
{
    use BrowserCsrfToken;
    public function testReadAndPreviewAreAuthorizedAndNeverPersist(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner] = $this->fixture($em);
        [, $foreign] = $this->fixture($em);
        $client->loginUser($owner);
        $base = '/api/v1/branches/'.$branch->id().'/operations/processes';
        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $catalog = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($catalog['persisted']);
        self::assertSame($branch->id(), $catalog['branch_id']);
        $process = $catalog['processes'][0];
        $procedure = $process['procedures'][0];
        $task = $procedure['tasks'][0];
        $client->request('GET', '/api/v1/branches/'.$foreign->id().'/operations/processes');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $this->csrfToken($client, 'branch_access');
        $input = [
            'process_id' => $process['id'],
            'procedure_id' => $procedure['id'],
            'task_id' => $task['id'],
            'state' => 'done',
        ];
        $client->jsonRequest('POST', '/api/v1/branches/'.$foreign->id().'/operations/processes/preview',
            $input, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $base.'/preview', $input, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $output = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('done', $output['preview']['state']);
        self::assertFalse($output['preview']['persisted']);
        self::assertFalse($output['preview']['dispatched']);
        $client->jsonRequest('POST', $base.'/preview', $input);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $base.'/preview', [...$input, 'tenant_id' => $tenant->id()],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', $base.'/preview', [...$input, 'procedure_id' => 'fixture:procedure/ajeno'],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);
    }
    public function testPermissionAndModuleFlagFailClosed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner] = $this->fixture($em);
        [, $disabledBranch, $disabledOwner] = $this->fixture($em, false);
        $viewer = new User('m11-viewer-'.bin2hex(random_bytes(4)).'@example.test', 'Viewer');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Solo lectura', ['inventory.view']);
        foreach ([$viewer, $membership, $role,
            new BranchRoleAssignment($membership, $branch, $role)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $base = '/api/v1/branches/'.$branch->id().'/operations/processes';
        $client->loginUser($viewer);
        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin');
        $csrf = $this->csrfToken($client, 'branch_access');
        $client->jsonRequest('POST', $base.'/preview', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($disabledOwner);
        $client->request('GET', '/api/v1/branches/'.$disabledBranch->id().'/operations/processes');
        self::assertResponseStatusCodeSame(422);
        $client->loginUser($owner);
        $client->request('GET', '/api/v1/branches/'.$disabledBranch->id().'/operations/processes');
        self::assertResponseStatusCodeSame(404);
    }
    /** @return array{Tenant,Branch,User} */
    private function fixture(EntityManagerInterface $em, bool $enabled = true): array
    {
        $seed = static::getContainer()->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $seed);
        $seed->seed();
        $salt = bin2hex(random_bytes(5));
        $tenant = new Tenant('Operaciones '.$salt, 'm11-'.$salt);
        $legal = new LegalEntity($tenant, 'M11 '.$salt.' SAS', null, true);
        $branch = new Branch($tenant, 'Sede', 'sede-'.$salt, $legal, true);
        $owner = new User('m11-'.$salt.'@example.test', 'Operador ficticio');
        foreach ([$tenant, $legal, $branch, $owner,
            new Membership($tenant, $owner, Membership::ROLE_OWNER)] as $item) {
            $em->persist($item);
        }
        $em->flush();
        $plan = $em->getRepository(Plan::class)->findOneBy(['key' => 'business']);
        $vertical = $em->getRepository(Vertical::class)->findOneBy(['key' => 'commerce']);
        $addon = $em->getRepository(AddOn::class)->findOneBy(['key' => 'production-lite']);
        self::assertInstanceOf(Plan::class, $plan);
        self::assertInstanceOf(Vertical::class, $vertical);
        self::assertInstanceOf(AddOn::class, $addon);
        $version = $em->getRepository(PlanVersion::class)->findOneBy(['plan' => $plan, 'version' => 1]);
        self::assertInstanceOf(PlanVersion::class, $version);
        $at = new DateTimeImmutable('2026-09-30T10:00:00Z');
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle($tenant->id(), $version, SubscriptionState::Active, $at), $at,
        );
        $em->persist($subscription);
        $em->persist(new SubscriptionConfiguration(
            $subscription, $vertical, array_filter($version->limits(), is_int(...)),
            $enabled ? [$addon] : [], $at,
        ));
        $em->flush();
        return [$tenant, $branch, $owner];
    }
}

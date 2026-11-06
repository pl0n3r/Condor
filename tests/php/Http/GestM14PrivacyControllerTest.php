<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
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
/** M14-S08: HTTP authorization, CSRF and strict fixture schema, not legal processing. */
final class GestM14PrivacyControllerTest extends WebTestCase
{
    use BrowserCsrfToken;
    public function testAnonymousWrongTenantAndPermissionAreDeniedBeforeSeeingFixtures(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$tenant, $branch, $owner] = $this->fixture($em);
        [, $foreign] = $this->fixture($em);
        $url = '/api/v1/branches/'.$branch->id().'/privacy/requests';
        $viewer = new User('m14-viewer-'.bin2hex(random_bytes(5)).'@example.test', 'Persona ficticia');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Solo inventario', ['inventory.view']);
        foreach ([$viewer, $membership, $role, new BranchRoleAssignment($membership, $branch, $role)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(401); // API authenticator rejects anonymous before branch guard.
        $client->loginUser($owner);
        $client->request('GET', '/api/v1/branches/'.$foreign->id().'/privacy/requests');
        self::assertResponseStatusCodeSame(404);
        $client->loginUser($viewer);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $url, ['kind' => 'access', 'subject_ref' => 'fixture:subject/demo']);
        self::assertResponseStatusCodeSame(403);
    }
    public function testSyntheticCreateConsultAndNegativeInputsWithoutRealSideEffects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [, $branch, $owner] = $this->fixture($em);
        $client->loginUser($owner);
        $base = '/api/v1/branches/'.$branch->id().'/privacy/requests';
        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $empty = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('empty', $empty['state']);
        self::assertSame([], $empty['requests']);
        self::assertTrue($empty['synthetic_only']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $valid = ['kind' => 'access', 'subject_ref' => 'fixture:subject/demo'];
        $client->jsonRequest('POST', $base, $valid);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $this->csrfToken($client, 'branch_access');
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];
        foreach ([
            ['kind' => 'access', 'subject_ref' => 'fixture:subject/demo', 'email' => 'real@example.com'],
            ['kind' => 'export', 'subject_ref' => 'fixture:subject/demo'],
            ['kind' => 'deletion', 'subject_ref' => 'customer:real-person'],
            ['kind' => 'access', 'subject_ref' => 'fixture:subject/demo', 'tenant_id' => 'other'],
            ['kind' => ['access'], 'subject_ref' => 'fixture:subject/demo'],
        ] as $illegal) {
            $client->jsonRequest('POST', $base, $illegal, $headers);
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('GET', $base.'?tenant_id=other');
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', $base, $valid, $headers);
        self::assertResponseStatusCodeSame(201);
        $receipt = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($receipt['persisted']);
        self::assertFalse($receipt['dispatched']);
        self::assertTrue($receipt['synthetic_only']);
        self::assertSame('pending', $receipt['legal_review']);
        self::assertSame('pending_review', $receipt['request']['status']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{16}\z/D', $receipt['request']['id']);
        self::assertArrayNotHasKey('email', $receipt['request']);
        $id = $receipt['request']['id'];
        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $listed = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ready', $listed['state']);
        self::assertCount(1, $listed['requests']);
        $client->request('GET', $base.'/'.$id);
        self::assertResponseIsSuccessful();
        $viewed = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($id, $viewed['request']['id']);
        $client->request('GET', $base.'/ffffffffffffffff');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $base.'/'.$id.'?email=real');
        self::assertResponseStatusCodeSame(422);
    }
    /** @return array{Tenant, Branch, User} */
    private function fixture(EntityManagerInterface $em): array
    {
        static::getContainer()->get(CommercialCatalogSeeder::class)->seed();
        $salt = bin2hex(random_bytes(5));
        $tenant = new Tenant('M14 ficticio '.$salt, 'm14-'.$salt);
        $legal = new LegalEntity($tenant, 'M14 Ficticio SAS', null, true);
        $branch = new Branch($tenant, 'Sede ficticia', 'm14-'.$salt, $legal, true);
        $owner = new User('m14-'.$salt.'@example.test', 'Titular ficticio');
        foreach ([$tenant, $legal, $branch, $owner,
            new Membership($tenant, $owner, Membership::ROLE_OWNER)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $plan = $em->getRepository(Plan::class)->findOneBy(['key' => 'business']);
        $vertical = $em->getRepository(Vertical::class)->findOneBy(['key' => 'commerce']);
        $version = $em->getRepository(PlanVersion::class)->findOneBy(['plan' => $plan, 'version' => 1]);
        $at = new DateTimeImmutable('2026-09-30T10:00:00Z');
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle($tenant->id(), $version, SubscriptionState::Active, $at), $at,
        );
        $em->persist($subscription);
        $em->persist(new SubscriptionConfiguration(
            $subscription, $vertical, array_filter($version->limits(), is_int(...)), [], $at,
        ));
        $em->flush();
        return [$tenant, $branch, $owner];
    }
}

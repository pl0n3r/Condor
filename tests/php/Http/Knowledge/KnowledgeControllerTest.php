<?php

declare(strict_types=1);

namespace App\Tests\Http\Knowledge;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class KnowledgeControllerTest extends WebTestCase
{
    public function testHelpCenterAndFaqUseSamePublishedCanonicalKnowledge(): void
    {
        $client = static::createClient();

        $help = $client->request('GET', '/ayuda');
        self::assertResponseIsSuccessful();
        self::assertSame(
            'account-access',
            $help->filter('[data-knowledge-id]')->attr('data-knowledge-id'),
        );
        self::assertSelectorTextContains('h1', 'Preguntas y respuestas');
        self::assertSelectorExists('link[rel="canonical"][href="/ayuda"]');
        self::assertSelectorTextNotContains('body', 'Ayuda contextual del administrador');
        self::assertSelectorTextNotContains('body', 'Checklist operativo de soporte');
        self::assertSelectorTextNotContains('body', 'Borrador todavía no publicado');

        $publicContent = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('COP', $publicContent);
        self::assertStringNotContainsString('capability:', $publicContent);
        self::assertStringNotContainsString('plan-version:', $publicContent);

        $faq = $client->request('GET', '/faq');
        self::assertResponseIsSuccessful();
        self::assertSame(
            'account-access',
            $faq->filter('[data-knowledge-id]')->attr('data-knowledge-id'),
        );
        self::assertSelectorExists('link[rel="canonical"][href="/ayuda"]');
    }

    public function testContextualHelpUsesTenantScopeAndCustomerVisibility(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(4));
        $user = new User('knowledge-'.$suffix.'@example.test', 'Knowledge User');
        $tenant = new Tenant('Knowledge '.$suffix, 'knowledge-'.$suffix);
        $membership = new Membership($tenant, $user, Membership::ROLE_OWNER);

        foreach ([$user, $tenant, $membership] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/ayuda');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'tenant:'.$tenant->id(),
            $crawler->filter('[data-knowledge-scope]')->attr('data-knowledge-scope'),
        );
        self::assertSame(
            'admin-context',
            $crawler->filter('[data-knowledge-id]')->attr('data-knowledge-id'),
        );
        self::assertSelectorTextContains('body', 'Ayuda contextual del administrador');
        self::assertSelectorTextNotContains('body', 'Recuperar acceso a Condor');
        self::assertSelectorTextNotContains('body', 'Checklist operativo de soporte');
        self::assertSelectorExists('meta[name="robots"][content="noindex,nofollow"]');
    }

    public function testUnknownModuleProducesExplicitHandoffInsteadOfGuessing(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/ayuda?module=unknown');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'handoff',
            $crawler->filter('[data-knowledge-status]')->attr('data-knowledge-status'),
        );
        self::assertSelectorTextContains(
            '[data-testid="knowledge-handoff"]',
            'No tenemos evidencia suficiente',
        );
        self::assertSelectorTextContains(
            '[data-testid="knowledge-handoff"]',
            'no va a inventar una respuesta',
        );
    }

    public function testContextualHelpRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/ayuda');

        self::assertResponseRedirects('/admin/login');
    }

    public function testInvalidModuleFailsAsBadRequest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ayuda?module=../../secret');

        self::assertResponseStatusCodeSame(400);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Http\Knowledge;

use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminKnowledgeControllerTest extends WebTestCase
{
    public function testOnlyPlatformOwnerCanAccessKnowledgeObservability(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'knowledge-normal-'.bin2hex(random_bytes(4)).'@example.test',
            'Knowledge Normal',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/adminpl0n3r/conocimiento');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerSeesLifecycleFreshnessGapsAndHonestUsageStates(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $owner = new User(
            'knowledge-owner-'.bin2hex(random_bytes(4)).'@example.test',
            'Knowledge Owner',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($owner);
        $entityManager->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/adminpl0n3r/conocimiento');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Estado de la fuente canónica');
        self::assertSame('3', $crawler->filter('[data-lifecycle="published"]')->text());
        self::assertSame('1', $crawler->filter('[data-lifecycle="draft"]')->text());
        self::assertSame('2', $crawler->filter('[data-testid="knowledge-stale-count"]')->text());
        self::assertSame('0', $crawler->filter('[data-testid="knowledge-gap-count"]')->text());
        self::assertSame('0', $crawler->filter('[data-testid="knowledge-unanswered-count"]')->text());
        self::assertSelectorTextContains('[data-channel="help_center"]', 'Sin telemetría observada');
        self::assertSelectorTextContains('[data-channel="contextual_help"]', 'Sin telemetría observada');
        self::assertSelectorTextContains('[data-channel="voice"]', 'Canal no habilitado');
        self::assertSelectorExists('[data-state="empty"]');
    }
}

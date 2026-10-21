<?php
declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Commercial\Entity\Quote;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #776: negativos HTTP de precios y módulos del configurador.
 * Todos los tenants y las IP son sintéticos; no se consulta producción.
 */
final class PlanConfiguratorHttpNegativesTest extends WebTestCase
{
    public function testRejectsInvalidCombinationsAndCalculatesTotalOnServer(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new CommercialCatalogSeeder($em))->seed();

        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Cliente A '.$suffix, 'quote-a-'.$suffix);
        $tenantB = new Tenant('Cliente B '.$suffix, 'quote-b-'.$suffix);
        $em->persist($tenantA);
        $em->persist($tenantB);
        $em->flush();

        $route = '/api/public/configurator/quote';
        $baseline = [
            'plan' => 'business',
            'vertical' => 'commerce',
            'cycle' => 'monthly',
            'quantities' => ['users' => 12, 'locations' => 3, 'companies' => 1],
            'addons' => [],
        ];

        // Unique synthetic IP per request prevents the public rate limiter
        // from hiding actual domain failures behind HTTP 429 responses.
        $invalidCases = [
            ['plan' => 'unknown-plan'],
            ['vertical' => 'unknown-vertical'],
            ['cycle' => 'weekly'],
            ['quantities' => ['users' => 0]],
            ['quantities' => ['locations' => -1]],
            ['quantities' => ['users' => '12']],
            ['quantities' => ['unknown_scale' => 1]],
            ['addons' => ['unknown-addon']],
            ['addons' => ['extra-user']],
            ['unknown_field' => 'no'],
        ];
        foreach ($invalidCases as $index => $overrides) {
            $client->jsonRequest(
                'POST',
                $route,
                array_replace($baseline, $overrides),
                server: ['REMOTE_ADDR' => '198.51.100.'.(40 + $index)],
            );
            self::assertResponseStatusCodeSame(
                422,
                'Input inválido #'.$index.' no fue rechazado por el servidor.',
            );
        }

        // A module from a different vertical must not be selectable here.
        $client->jsonRequest('POST', $route, [
            'plan' => 'pro',
            'vertical' => 'legal',
            'cycle' => 'monthly',
            'quantities' => ['users' => 30, 'locations' => 10, 'companies' => 5],
            'addons' => ['production-lite'],
        ], server: ['REMOTE_ADDR' => '198.51.100.60']);
        self::assertResponseStatusCodeSame(422);

        $before = $em->getRepository(Quote::class)->count([]);
        $client->jsonRequest(
            'POST',
            $route,
            $baseline + ['total' => 1],
            server: ['REMOTE_ADDR' => '198.51.100.61'],
        );
        self::assertResponseStatusCodeSame(200);
        $response = (string) $client->getResponse()->getContent();
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertArrayHasKey('quote', $result);
        self::assertSame(229700, $result['quote']['total_amount']);
        self::assertNotSame(1, $result['quote']['total_amount']);
        self::assertFalse($result['quote']['proposal_required']);
        self::assertSame($before, $em->getRepository(Quote::class)->count([]));
        self::assertStringNotContainsString('Cliente A '.$suffix, $response);
        self::assertStringNotContainsString('Cliente B '.$suffix, $response);
    }
}

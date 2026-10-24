<?php
declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** HTTP real, dos tenants sintéticos y cero datos personales persistidos. */
final class ConfiguratorTelemetryHttpSmokeTest extends WebTestCase
{
    public function testPrivacyBodyBoundsAndRateLimitOnPublicRoute(): void
    {
        $client = static::createClient();
        // Mantiene el storage del rate limiter entre peticiones HTTP.
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new CommercialCatalogSeeder($em))->seed();

        $suffix = bin2hex(random_bytes(6));
        $aName = 'PRIVADO_TENANT_A_'.$suffix;
        $bName = 'PRIVADO_TENANT_B_'.$suffix;
        $tenantA = new Tenant($aName, 'telemetry-a-'.$suffix);
        $tenantB = new Tenant($bName, 'telemetry-b-'.$suffix);
        $em->persist($tenantA);
        $em->persist($tenantB);
        $em->flush();

        $repository = $em->getRepository(FunctionalSignal::class);
        $before = [];
        foreach ($repository->findBy(['type' => FunctionalSignal::CONFIGURATOR_FUNNEL]) as $signal) {
            $before[$signal->id()] = true;
        }
        $route = '/api/public/configurator/events';
        $headersA = [
            'REMOTE_ADDR' => '198.51.100.181',
            'HTTP_X_TENANT_ID' => $tenantA->id(),
        ];
        $headersB = [
            'REMOTE_ADDR' => '198.51.100.182',
            'HTTP_X_TENANT_ID' => $tenantB->id(),
        ];
        $invalidCases = [
            [['event' => 'start', 'email' => 'private@example.test'], 422],
            [['event' => 'start', 'tenant' => $tenantB->id()], 422],
            [['event' => 'start', 'name' => $aName], 422],
            [['event' => 'unknown'], 422],
            [['event' => ['start']], 422],
            [['cycle' => 'monthly'], 422],
            [['event' => 'start'.str_repeat(' ', 8192)], 413],
        ];
        foreach ($invalidCases as $index => [$body, $expected]) {
            $client->jsonRequest('POST', $route, $body, server: [
                'REMOTE_ADDR' => '198.51.100.'.(190 + $index),
                'HTTP_X_TENANT_ID' => $tenantA->id(),
            ]);
            self::assertResponseStatusCodeSame($expected);
        }
        $client->request(
            'POST', $route, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '198.51.100.200'] + $headersA,
            '{this is not JSON',
        );
        self::assertResponseStatusCodeSame(400);

        // Límite inclusivo: 4096 bytes de JSON válido pasan; 8192 falló 413.
        $minimalBody = '{"event":"start"}';
        $boundaryBody = '{"event":"start'.str_repeat(' ', 4096 - strlen($minimalBody)).'"}';
        self::assertSame(4096, strlen($boundaryBody));
        $client->request(
            'POST', $route, [], [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '198.51.100.183'],
            $boundaryBody,
        );
        self::assertResponseStatusCodeSame(202);

        // Una carga válida se guarda sin correo, IP, tenant ni headers.
        $event = [
            'event' => 'plan_selected',
            'plan' => 'business',
            'vertical' => 'commerce',
            'cycle' => 'monthly',
            'addon' => 'extra-user',
            'step' => 'plan',
        ];
        $client->jsonRequest('POST', $route, $event, server: $headersA);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['accepted' => true], json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        ));

        // La rate limit de test es 2/h por IP, sin privilegios por tenant.
        $client->jsonRequest('POST', $route, ['event' => 'completion'], server: $headersB);
        self::assertResponseStatusCodeSame(202);
        $client->jsonRequest('POST', $route, ['event' => 'completion'], server: $headersB);
        self::assertResponseStatusCodeSame(202);
        $client->jsonRequest('POST', $route, ['event' => 'completion'], server: $headersB);
        self::assertResponseStatusCodeSame(429);
        self::assertNotEmpty($client->getResponse()->headers->get('Retry-After'));

        $newSignals = [];
        foreach ($repository->findBy(['type' => FunctionalSignal::CONFIGURATOR_FUNNEL]) as $signal) {
            if (!isset($before[$signal->id()])) {
                $newSignals[] = $signal;
            }
        }
        self::assertCount(4, $newSignals, 'Rechazos y rate limit no deben generar señales.');
        $contexts = [];
        foreach ($newSignals as $signal) {
            self::assertNull($signal->tenantId(), 'Telemetry pública nunca ata tenant.');
            $context = $signal->context();
            foreach (array_keys($context) as $field) {
                self::assertContains($field, [
                    'event', 'plan', 'vertical', 'cycle', 'addon', 'step',
                ]);
            }
            $json = json_encode($context, JSON_THROW_ON_ERROR);
            foreach ([$aName, $bName, $tenantA->id(), $tenantB->id(), '@', '198.51.100'] as $private) {
                self::assertStringNotContainsString($private, $json);
            }
            $contexts[] = $context;
        }
        self::assertContains($event, $contexts);
    }
}

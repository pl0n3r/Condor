<?php

declare(strict_types=1);

namespace App\Tests\Http\Controller;

use App\Infrastructure\Payments\Wompi\WompiSandboxHttpTransport;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WompiSandboxWebhookControllerTest extends WebTestCase
{
    public function testWebhookAlwaysRejectsWithoutSandboxFlag(): void
    {
        $old = getenv('CONDOR_PAYMENTS_SANDBOX_ENABLED');
        putenv('CONDOR_PAYMENTS_SANDBOX_ENABLED=false');
        try {
            $client = self::createClient();
            $client->request('POST', '/webhooks/wompi/sandbox', server: [
                'HTTP_X_EVENT_CHECKSUM' => str_repeat('0', 64),
            ], content: '{"event":"transaction.updated"}');
            self::assertResponseStatusCodeSame(423);
        } finally {
            putenv($old === false ? 'CONDOR_PAYMENTS_SANDBOX_ENABLED'
                : 'CONDOR_PAYMENTS_SANDBOX_ENABLED='.$old);
        }
    }

    public function testPublicWebhookOnlyAcceptsVerifiedEventWithNoSideEffects(): void
    {
        $old = getenv('CONDOR_PAYMENTS_SANDBOX_ENABLED');
        putenv('CONDOR_PAYMENTS_SANDBOX_ENABLED=true');
        try {
            $client = self::createClient();
            $client->disableReboot();
            $fake = $this->createMock(WompiSandboxHttpTransport::class);
            $fake->expects(self::exactly(2))->method('verifyEvent')->willReturnOnConsecutiveCalls(false, true);
            self::getContainer()->set(WompiSandboxHttpTransport::class, $fake);

            $client->request('POST', '/webhooks/wompi/sandbox', server: [
                'HTTP_X_EVENT_CHECKSUM' => str_repeat('0', 64),
            ], content: '{"event":"invalid"}');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/webhooks/wompi/sandbox', server: [
                'HTTP_X_EVENT_CHECKSUM' => str_repeat('a', 64),
            ], content: '{"event":"transaction.updated"}');
            self::assertResponseStatusCodeSame(200);
            self::assertSame(['accepted' => true], json_decode(
                (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
            ));
        } finally {
            putenv($old === false ? 'CONDOR_PAYMENTS_SANDBOX_ENABLED'
                : 'CONDOR_PAYMENTS_SANDBOX_ENABLED='.$old);
        }
    }
}

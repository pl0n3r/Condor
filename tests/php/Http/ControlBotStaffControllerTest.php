<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Domain\Identity\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ControlBotStaffControllerTest extends WebTestCase
{
    private const KEY_ID = 'product-1';
    private const KEY = 'test-controlbot-key-32-bytes-minimum-value';

    protected function tearDown(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('DROP TRIGGER IF EXISTS condor_controlbot_audit_fail');
        $db->executeStatement('DELETE FROM condor_controlbot_audit');
        $db->executeStatement('DELETE FROM condor_controlbot_idempotency');
        $db->executeStatement('DELETE FROM condor_controlbot_nonce');
        $db->executeStatement(
            "DELETE FROM condor_user WHERE email LIKE 'controlbot-%@example.test'"
        );

        putenv('CONDOR_CONTROLBOT_KEY_ID');
        putenv('CONDOR_CONTROLBOT_KEY');
        putenv('CONDOR_CONTROLBOT_ALLOWED_IPS');
        putenv('CONDOR_EPHEMERAL_CACHE');
        parent::tearDown();
    }

    public function testOpsRoutesAre404WithoutControlbotKey(): void
    {
        putenv('CONDOR_EPHEMERAL_CACHE=1');
        $client = static::createClient();
        $router = static::getContainer()->get('router');
        self::assertNull(
            $router->getRouteCollection()->get('controlbot_ops_summary'),
            'Sin configuración M2M, /ops no debe registrarse en el router.',
        );

        $client->request(
            'GET',
            '/ops/summary',
            server: ['HTTPS' => 'on', 'SERVER_PORT' => '443'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testFactoryKnownAnswerAndCanonicalQuery(): void
    {
        $request = \Symfony\Component\HttpFoundation\Request::create(
            'https://www.condorapp.com.co/ops/staff?role=admin&q=Ana%20Mar%C3%ADa',
            'GET',
        );
        $canonical = \App\Infrastructure\Security\ControlBotCanonicalRequest::canonical(
            $request,
            'product-1',
            '1750000000',
            '0123456789abcdef0123456789abcdef',
        );
        self::assertSame(
            "product-1\nGET\n/ops/staff?q=Ana%20Mar%C3%ADa&role=admin\n"
                ."1750000000\n0123456789abcdef0123456789abcdef\n"
                ."e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            $canonical,
        );
        self::assertSame(
            '84cff31494ee89cc3961c33db0d93dfe7ddcfd1fc505841b0d3de9ddbf31b419',
            hash_hmac('sha256', $canonical, 'test-secret-not-production'),
        );

        $duplicates = \Symfony\Component\HttpFoundation\Request::create(
            'https://www.condorapp.com.co/ops/staff?tag=b&tag=a&space=hello%20world',
            'GET',
        );
        self::assertSame(
            '/ops/staff?space=hello%20world&tag=a&tag=b',
            \App\Infrastructure\Security\ControlBotCanonicalRequest::pathWithSortedQuery($duplicates),
        );
    }

    public function testFactoryContractRejectsCleartextAndRawPlus(): void
    {
        putenv('CONDOR_CONTROLBOT_KEY_ID='.self::KEY_ID);
        putenv('CONDOR_CONTROLBOT_KEY='.self::KEY);
        putenv('CONDOR_CONTROLBOT_ALLOWED_IPS=127.0.0.1');
        putenv('CONDOR_EPHEMERAL_CACHE=1');
        $client = static::createClient([], ['REMOTE_ADDR' => '127.0.0.1']);
        $headers = $this->signedHeaders('GET', '/ops/summary', '', 'nonce_cleartext_'.bin2hex(random_bytes(6)));
        $client->request('GET', '/ops/summary', server: $headers);
        self::assertResponseStatusCodeSame(403);

        $request = \Symfony\Component\HttpFoundation\Request::create(
            'https://www.condorapp.com.co/ops/staff?q=A+B',
            'GET',
        );
        $this->expectException(
            \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException::class,
        );
        \App\Infrastructure\Security\ControlBotCanonicalRequest::pathWithSortedQuery($request);
    }

    public function testSignedSummaryIsStaffOnlyAndReplayIsRejected(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));
        $staff = new User('controlbot-staff-'.$suffix.'@example.test', 'Staff', [User::ROLE_PLATFORM_STAFF]);
        $customer = new User('controlbot-customer-'.$suffix.'@example.test', 'Cliente');
        $owner = new User(
            'controlbot-summary-owner-'.$suffix.'@example.test',
            'Owner Summary',
            [User::ROLE_PLATFORM_OWNER],
        );
        $superAdmin = new User(
            'controlbot-summary-super-'.$suffix.'@example.test',
            'Super Summary',
            [User::ROLE_LEGACY_SUPER_ADMIN],
        );
        $invited = new User(
            'controlbot-summary-invited-'.$suffix.'@example.test',
            'Invited Summary',
            [User::ROLE_PLATFORM_STAFF],
        );
        $invited->deactivate();
        $invitation = new \App\Domain\Identity\Entity\AccountInvitation(
            $invited,
            null,
            \App\Domain\Identity\Entity\AccountInvitation::KIND_PLATFORM_STAFF,
            hash('sha256', 'summary-invitation-'.$suffix),
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
            'CONTROLBOT000000000000000',
        );
        $em->persist($staff);
        $em->persist($customer);
        $em->persist($owner);
        $em->persist($superAdmin);
        $em->persist($invited);
        $em->persist($invitation);
        $em->flush();

        $headers = $this->signedHeaders('GET', '/ops/summary', '', 'nonce_'.$suffix.'_summary');
        $client->request('GET', '/ops/summary', server: $headers);
        self::assertResponseIsSuccessful();
        $summary = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            ['active', 'suspended', 'invited', 'recent_failed_logins'],
            array_keys($summary),
        );
        self::assertNotNull(
            static::getContainer()->get('router')
                ->getRouteCollection()
                ->get('controlbot_ops_summary'),
            'Con configuración M2M válida, /ops debe estar registrado.',
        );
        self::assertSame(1, $summary['active']);
        self::assertSame(0, $summary['suspended']);
        self::assertSame(1, $summary['invited']);
        self::assertIsInt($summary['recent_failed_logins']);
        $client->request('GET', '/ops/summary', server: $headers);
        self::assertResponseStatusCodeSame(409);
    }

    public function testFutureTimestampNonceCoversFullValidityWindow(): void
    {
        $client = $this->client();
        $db = static::getContainer()->get(Connection::class);
        $timestamp = time() + 299;
        $nonce = 'nonce_future_'.bin2hex(random_bytes(6));
        $client->request('GET', '/ops/summary', server: $this->signedHeaders('GET', '/ops/summary', '', $nonce, $timestamp));
        self::assertResponseIsSuccessful();
        $expires = strtotime((string) $db->fetchOne(
            'SELECT expires_at FROM condor_controlbot_nonce WHERE key_id = ? AND nonce_hash = ?',
            [self::KEY_ID, hash('sha256', $nonce)],
        ));
        self::assertGreaterThanOrEqual(time() + 598, $expires);
        self::assertLessThanOrEqual(time() + 602, $expires);
    }

    public function testRateLimitFailsClosedAfterConfiguredWindow(): void
    {
        $ip = '127.0.0.77';
        $client = $this->client($ip);
        $suffix = bin2hex(random_bytes(4));
        for ($i = 0; $i < 60; ++$i) {
            $nonce = $this->validNonce('rate_'.$i, $suffix);
            $client->request(
                'GET',
                '/ops/summary',
                server: $this->signedHeaders('GET', '/ops/summary', '', $nonce),
            );
            self::assertResponseIsSuccessful();
        }

        $client->request(
            'GET',
            '/ops/summary',
            server: $this->signedHeaders('GET', '/ops/summary', '', 'nonce_rate_blocked_'.$suffix),
        );
        self::assertResponseStatusCodeSame(429);
    }

    public function testAuditFailureRollsBackStaffMutation(): void
    {
        $client = $this->client('127.0.0.78');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = static::getContainer()->get(Connection::class);
        $suffix = bin2hex(random_bytes(4));
        $staff = new User('controlbot-atomic-'.$suffix.'@example.test', 'Atomic Staff', [User::ROLE_PLATFORM_STAFF]);
        $em->persist($staff);
        $em->flush();

        $db->executeStatement('DROP TRIGGER IF EXISTS condor_controlbot_audit_fail');
        $db->executeStatement(<<<'SQL'
            CREATE TRIGGER condor_controlbot_audit_fail
            BEFORE INSERT ON condor_controlbot_audit
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced audit failure'
            SQL);
        try {
            $uri = '/ops/staff/'.$staff->id().'/suspend';
            $body = json_encode(['reason_code' => 'audit_failure'], JSON_THROW_ON_ERROR);
            $client->request(
                'POST',
                $uri,
                server: $this->signedHeaders(
                    'POST',
                    $uri,
                    $body,
                    $this->validNonce('atomic', $suffix),
                ),
                content: $body,
            );
            self::assertResponseStatusCodeSame(500);
        } finally {
            $db->executeStatement('DROP TRIGGER IF EXISTS condor_controlbot_audit_fail');
        }

        self::assertSame(
            1,
            (int) $db->fetchOne('SELECT active FROM condor_user WHERE id = ?', [$staff->id()]),
            'La mutación debe revertirse si no puede persistirse su auditoría.',
        );
    }

    public function testStaffCreationFailsClosedWithoutDeliveryAdapter(): void
    {
        $client = $this->client();
        $suffix = bin2hex(random_bytes(4));
        $uri = '/ops/staff';
        $json = json_encode(['email' => 'controlbot-blocked-'.$suffix.'@example.test', 'name' => 'Blocked', 'role' => 'staff'], JSON_THROW_ON_ERROR);
        $client->request('POST', $uri, server: $this->signedHeaders('POST', $uri, $json, 'nonce_'.$suffix.'_blocked'), content: $json);
        self::assertResponseStatusCodeSame(503);
    }

    public function testStaffCreationDeliversInvitationWithoutExposingToken(): void
    {
        $client = $this->client();
        $gateway = new class implements TransactionalEmailGateway {
            /** @var list<TransactionalEmailMessage> */
            public array $messages = [];
            public function isAvailable(): bool { return true; }
            public function deliver(TransactionalEmailMessage $message): void { $this->messages[] = $message; }
        };
        static::getContainer()->set(TransactionalEmailGateway::class, $gateway);

        $suffix = bin2hex(random_bytes(4));
        $uri = '/ops/staff';
        $json = json_encode(['email' => 'controlbot-botstaff-'.$suffix.'@example.test', 'name' => 'Bot Staff '.$suffix, 'role' => 'staff'], JSON_THROW_ON_ERROR);
        $client->request('POST', $uri, server: $this->signedHeaders('POST', $uri, $json, $this->validNonce('create', $suffix)), content: $json);
        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invited', $payload['status']);
        self::assertTrue($payload['invitation_sent']);
        self::assertArrayHasKey('id', $payload);
        self::assertArrayNotHasKey('token', $payload);
        self::assertCount(1, $gateway->messages);
        self::assertArrayHasKey('activation_token', $gateway->messages[0]->templateData);
        self::assertStringNotContainsString(
            (string) $gateway->messages[0]->templateData['activation_token'],
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testFailedAuditDoesNotDeliverInvitation(): void
    {
        $client = $this->client('127.0.0.79');
        $gateway = new class implements TransactionalEmailGateway {
            /** @var list<TransactionalEmailMessage> */
            public array $messages = [];
            public function isAvailable(): bool { return true; }
            public function deliver(TransactionalEmailMessage $message): void { $this->messages[] = $message; }
        };
        static::getContainer()->set(TransactionalEmailGateway::class, $gateway);
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('DROP TRIGGER IF EXISTS condor_controlbot_audit_fail');
        $db->executeStatement(<<<'SQL'
            CREATE TRIGGER condor_controlbot_audit_fail
            BEFORE INSERT ON condor_controlbot_audit
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced audit failure'
            SQL);
        try {
            $suffix = bin2hex(random_bytes(4));
            $uri = '/ops/staff';
            $json = json_encode([
                'email' => 'controlbot-audit-fail-'.$suffix.'@example.test',
                'name' => 'Audit Fail',
                'role' => 'staff',
            ], JSON_THROW_ON_ERROR);
            $client->request(
                'POST',
                $uri,
                server: $this->signedHeaders('POST', $uri, $json, 'nonce_'.$suffix.'_audit_fail'),
                content: $json,
            );
            self::assertResponseStatusCodeSame(500);
            self::assertCount(0, $gateway->messages);
        } finally {
            $db->executeStatement('DROP TRIGGER IF EXISTS condor_controlbot_audit_fail');
        }
    }

    public function testPendingInvitationDeliveryCanBeRetriedAfterRevokeFailure(): void
    {
        $client = $this->client('127.0.0.81');
        $gateway = new class implements TransactionalEmailGateway {
            public bool $fail = true;
            /** @var list<TransactionalEmailMessage> */
            public array $messages = [];
            public function isAvailable(): bool { return true; }
            public function deliver(TransactionalEmailMessage $message): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('forced delivery failure');
                }
                $this->messages[] = $message;
            }
        };
        static::getContainer()->set(TransactionalEmailGateway::class, $gateway);
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('DROP TRIGGER IF EXISTS condor_invitation_revoke_fail');
        $db->executeStatement(<<<'SQL'
            CREATE TRIGGER condor_invitation_revoke_fail
            BEFORE UPDATE ON condor_account_invitation
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced revoke failure'
            SQL);
        $suffix = bin2hex(random_bytes(4));
        $uri = '/ops/staff';
        $body = json_encode([
            'email' => 'controlbot-pending-'.$suffix.'@example.test',
            'name' => 'Pending Delivery',
            'role' => 'staff',
        ], JSON_THROW_ON_ERROR);
        $key = 'idem-pending-'.$suffix;

        try {
            $client->request(
                'POST',
                $uri,
                server: $this->signedHeaders(
                    'POST',
                    $uri,
                    $body,
                    $this->validNonce('pending_first', $suffix),
                    null,
                    $key,
                ),
                content: $body,
            );
            self::assertResponseStatusCodeSame(500);
        } finally {
            $db->executeStatement('DROP TRIGGER IF EXISTS condor_invitation_revoke_fail');
        }

        $pending = $db->fetchAssociative(
            "SELECT response_status,response_body FROM condor_controlbot_idempotency "
            ."WHERE action='invite_staff' AND target_key=? LIMIT 1",
            ['controlbot-pending-'.$suffix.'@example.test'],
        );
        self::assertIsArray($pending);
        self::assertSame(202, (int) $pending['response_status']);
        self::assertSame(
            'pending_delivery',
            json_decode((string) $pending['response_body'], true, 512, JSON_THROW_ON_ERROR)['state'] ?? null,
        );

        $gateway->fail = false;
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                $body,
                $this->validNonce('pending_retry', $suffix),
                null,
                $key,
            ),
            content: $body,
        );
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $gateway->messages);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['invitation_sent']);
    }

    public function testInvitedStaffCannotBeReactivatedBeforeInvitationConsumption(): void
    {
        $client = $this->client();
        $gateway = new class implements TransactionalEmailGateway {
            public function isAvailable(): bool { return true; }
            public function deliver(TransactionalEmailMessage $message): void {}
        };
        static::getContainer()->set(TransactionalEmailGateway::class, $gateway);
        $suffix = bin2hex(random_bytes(4));
        $createUri = '/ops/staff';
        $json = json_encode([
            'email' => 'controlbot-invited-'.$suffix.'@example.test',
            'name' => 'Invited Staff',
            'role' => 'staff',
        ], JSON_THROW_ON_ERROR);
        $client->request(
            'POST',
            $createUri,
            server: $this->signedHeaders('POST', $createUri, $json, $this->validNonce('invite', $suffix)),
            content: $json,
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = (string)$created['id'];

        $searchUri = '/ops/staff?q='.rawurlencode('Invited Staff');
        $client->request(
            'GET',
            $searchUri,
            server: $this->signedHeaders('GET', $searchUri, '', $this->validNonce('search', $suffix)),
        );
        self::assertResponseIsSuccessful();
        $search = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invited', $search['items'][0]['status']);

        $uri = '/ops/staff/'.$id.'/reactivate';
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders('POST', $uri, '', $this->validNonce('reactivate', $suffix)),
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testDeliveryFailureRevokesPersistedInvitation(): void
    {
        $client = $this->client('127.0.0.80');
        $gateway = new class implements TransactionalEmailGateway {
            public function isAvailable(): bool { return true; }
            public function deliver(TransactionalEmailMessage $message): void
            {
                throw new \RuntimeException('forced delivery failure');
            }
        };
        static::getContainer()->set(TransactionalEmailGateway::class, $gateway);
        $db = static::getContainer()->get(Connection::class);
        $suffix = bin2hex(random_bytes(4));
        $email = 'controlbot-delivery-fail-'.$suffix.'@example.test';
        $uri = '/ops/staff';
        $json = json_encode([
            'email' => $email,
            'name' => 'Delivery Fail',
            'role' => 'staff',
        ], JSON_THROW_ON_ERROR);

        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                $json,
                $this->validNonce('delivery_fail', $suffix),
            ),
            content: $json,
        );

        self::assertResponseStatusCodeSame(503);
        $revokedAt = $db->fetchOne(
            'SELECT invitation.revoked_at '
            .'FROM condor_account_invitation invitation '
            .'INNER JOIN condor_user user ON user.id = invitation.user_id '
            .'WHERE user.email = ? LIMIT 1',
            [$email],
        );
        self::assertNotFalse($revokedAt);
        self::assertNotNull($revokedAt);
    }

    public function testPlatformOwnerCannotBeMutatedByControlBot(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = static::getContainer()->get(Connection::class);
        $suffix = bin2hex(random_bytes(4));
        $owner = new User(
            'controlbot-owner-'.$suffix.'@example.test',
            'Owner '.$suffix,
            [User::ROLE_PLATFORM_OWNER],
        );
        $em->persist($owner);
        $em->flush();

        $searchUri = '/ops/staff?q='.rawurlencode($suffix);
        $client->request(
            'GET',
            $searchUri,
            server: $this->signedHeaders('GET', $searchUri, '', 'nonce_'.$suffix.'_owner_read'),
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertCount(1, $payload['items']);
        self::assertSame($owner->id(), $payload['items'][0]['id']);
        self::assertSame('owner', $payload['items'][0]['role']);
        self::assertStringContainsString('***@example.test', $payload['items'][0]['email_masked']);

        $ownerId = $owner->id();
        $uri = '/ops/staff/'.$ownerId.'/suspend';
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders('POST', $uri, '', 'nonce_'.$suffix.'_owner_mutate'),
        );
        self::assertResponseStatusCodeSame(404);
        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $freshOwner = $freshEm->find(User::class, $ownerId);
        self::assertInstanceOf(User::class, $freshOwner);
        self::assertTrue($freshOwner->isActive());
        self::assertTrue($freshOwner->hasRole(User::ROLE_PLATFORM_OWNER));
        self::assertGreaterThanOrEqual(1, (int) $db->fetchOne(
            "SELECT COUNT(*) FROM condor_controlbot_audit WHERE action = 'controlbot.staff.suspend_rejected' AND target_staff_user_id = ?",
            [$ownerId],
        ));
    }

    public function testPasswordResetFailsClosedUntilIssue191IsIntegrated(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));
        $staff = new User('controlbot-reset-'.$suffix.'@example.test', 'Reset Staff', [User::ROLE_PLATFORM_STAFF]);
        $em->persist($staff);
        $em->flush();
        $uri = '/ops/staff/'.$staff->id().'/password-reset';
        $client->request('POST', $uri, server: $this->signedHeaders('POST', $uri, '', $this->validNonce('reset', $suffix)));
        self::assertResponseStatusCodeSame(409);
    }

    public function testMutationsRequireIdempotencyKey(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));
        $staff = new User(
            'controlbot-idem-required-'.$suffix.'@example.test',
            'Idempotency Required',
            [User::ROLE_PLATFORM_STAFF],
        );
        $em->persist($staff);
        $em->flush();

        $uri = '/ops/staff/'.$staff->id().'/suspend';
        $headers = $this->signedHeaders(
            'POST',
            $uri,
            '',
            $this->validNonce('idem_required', $suffix),
        );
        unset($headers['HTTP_IDEMPOTENCY_KEY']);
        $client->request('POST', $uri, server: $headers);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue($staff->isActive());
    }

    public function testIdempotentSuspendReplaysWithoutSecondSideEffect(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = static::getContainer()->get(Connection::class);
        $suffix = bin2hex(random_bytes(4));
        $staff = new User(
            'controlbot-idem-replay-'.$suffix.'@example.test',
            'Idempotent Staff',
            [User::ROLE_PLATFORM_STAFF],
        );
        $em->persist($staff);
        $em->flush();

        $uri = '/ops/staff/'.$staff->id().'/suspend';
        $body = json_encode(['reason_code' => 'security'], JSON_THROW_ON_ERROR);
        $key = 'idem-replay-'.$suffix;
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                $body,
                $this->validNonce('idem_first', $suffix),
                null,
                $key,
            ),
            content: $body,
        );
        self::assertResponseIsSuccessful();
        $first = (string) $client->getResponse()->getContent();

        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                $body,
                $this->validNonce('idem_second', $suffix),
                null,
                $key,
            ),
            content: $body,
        );
        self::assertResponseIsSuccessful();
        self::assertSame($first, (string) $client->getResponse()->getContent());
        self::assertSame(1, (int) $db->fetchOne(
            "SELECT COUNT(*) FROM condor_controlbot_audit "
            ."WHERE action = 'controlbot.staff.suspended' "
            ."AND target_staff_user_id = ?",
            [$staff->id()],
        ));
    }

    public function testIdempotencyKeyRejectsDifferentFingerprint(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));
        $staff = new User(
            'controlbot-idem-conflict-'.$suffix.'@example.test',
            'Idempotency Conflict',
            [User::ROLE_PLATFORM_STAFF],
        );
        $em->persist($staff);
        $em->flush();

        $uri = '/ops/staff/'.$staff->id().'/suspend';
        $key = 'idem-conflict-'.$suffix;
        $first = json_encode(['reason_code' => 'security'], JSON_THROW_ON_ERROR);
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                $first,
                $this->validNonce('idem_conflict_a', $suffix),
                null,
                $key,
            ),
            content: $first,
        );
        self::assertResponseIsSuccessful();

        $second = json_encode(['reason_code' => 'policy'], JSON_THROW_ON_ERROR);
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                $second,
                $this->validNonce('idem_conflict_b', $suffix),
                null,
                $key,
            ),
            content: $second,
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testLegacySuperAdminCannotBeMutatedByControlBot(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));
        $superAdmin = new User(
            'controlbot-superadmin-'.$suffix.'@example.test',
            'Legacy Superadmin',
            [User::ROLE_PLATFORM_STAFF, User::ROLE_LEGACY_SUPER_ADMIN],
        );
        $em->persist($superAdmin);
        $em->flush();

        $uri = '/ops/staff/'.$superAdmin->id().'/suspend';
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                '',
                $this->validNonce('superadmin', $suffix),
            ),
        );
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($superAdmin->isActive());
    }

    public function testSearchRequiresQueryAndPaginatesContractShape(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));
        for ($i = 0; $i < 26; ++$i) {
            $staff = new User(
                sprintf('controlbot-page-%02d-%s@example.test', $i, $suffix),
                'Page Staff '.$suffix.' '.$i,
                [User::ROLE_PLATFORM_STAFF],
            );
            if ($i === 0) {
                $staff->markAccessedAt(new \DateTimeImmutable('2026-09-26T07:00:00+00:00'));
            }
            $em->persist($staff);
        }
        $em->flush();

        $missingUri = '/ops/staff';
        $client->request(
            'GET',
            $missingUri,
            server: $this->signedHeaders(
                'GET',
                $missingUri,
                '',
                $this->validNonce('search_missing', $suffix),
            ),
        );
        self::assertResponseStatusCodeSame(422);

        $uri = '/ops/staff?q='.rawurlencode('Page Staff '.$suffix);
        $client->request(
            'GET',
            $uri,
            server: $this->signedHeaders(
                'GET',
                $uri,
                '',
                $this->validNonce('search_page_one', $suffix),
            ),
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(25, $payload['limit']);
        self::assertCount(25, $payload['items']);
        self::assertNotNull($payload['next_cursor']);
        self::assertSame(
            ['id', 'name', 'email_masked', 'role', 'status', 'last_access_at'],
            array_keys($payload['items'][0]),
        );

        $nextUri = '/ops/staff?cursor='
            .rawurlencode((string) $payload['next_cursor'])
            .'&q='.rawurlencode('Page Staff '.$suffix);
        $client->request(
            'GET',
            $nextUri,
            server: $this->signedHeaders(
                'GET',
                $nextUri,
                '',
                $this->validNonce('search_page_two', $suffix),
            ),
        );
        self::assertResponseIsSuccessful();
        $second = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertCount(1, $second['items']);
        self::assertNull($second['next_cursor']);
    }

    public function testSuspendRequiresReasonCodeAndReturnsContractShape(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));
        $staff = new User(
            'controlbot-reason-'.$suffix.'@example.test',
            'Reason Staff',
            [User::ROLE_PLATFORM_STAFF],
        );
        $em->persist($staff);
        $em->flush();

        $uri = '/ops/staff/'.$staff->id().'/suspend';
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                '{}',
                $this->validNonce('reason_missing', $suffix),
            ),
            content: '{}',
        );
        self::assertResponseStatusCodeSame(422);

        $body = json_encode(['reason_code' => 'security_review'], JSON_THROW_ON_ERROR);
        $client->request(
            'POST',
            $uri,
            server: $this->signedHeaders(
                'POST',
                $uri,
                $body,
                $this->validNonce('reason_present', $suffix),
            ),
            content: $body,
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($staff->id(), $payload['id']);
        self::assertSame('suspended', $payload['status']);
    }

    public function testUserEqualityInvalidatesSessionAuthorityChanges(): void
    {
        $user = new User(
            'controlbot-equality@example.test',
            'Equality Staff',
            [User::ROLE_PLATFORM_STAFF],
        );
        $snapshot = clone $user;
        self::assertTrue($user->isEqualTo($snapshot));

        $user->deactivate();
        self::assertFalse($user->isEqualTo($snapshot));

        $user->activate();
        $snapshot = clone $user;
        $user->grantRole(User::ROLE_LEGACY_SUPER_ADMIN);
        self::assertFalse($user->isEqualTo($snapshot));
    }

    private function client(string $ip = '127.0.0.1'): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        putenv('CONDOR_CONTROLBOT_KEY_ID='.self::KEY_ID);
        putenv('CONDOR_CONTROLBOT_KEY='.self::KEY);
        putenv('CONDOR_CONTROLBOT_ALLOWED_IPS='.$ip);
        putenv('CONDOR_EPHEMERAL_CACHE=1');
        return static::createClient([], [
            'REMOTE_ADDR' => $ip,
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ]);
    }

    private function validNonce(string $purpose, string $suffix): string
    {
        return 'nonce_'.$purpose.'_'.$suffix.'_fixture';
    }

    /** @return array<string, string> */
    private function signedHeaders(
        string $method,
        string $uri,
        string $body,
        string $nonce,
        ?int $timestamp = null,
        ?string $idempotencyKey = null,
    ): array {
        $timestamp ??= time();
        $raw = (string) $timestamp;
        $canonical = implode("\n", [
            self::KEY_ID,
            strtoupper($method),
            $uri,
            $raw,
            $nonce,
            hash('sha256', $body),
        ]);
        $headers = [
            'HTTP_X_FACTORY_KEY_ID' => self::KEY_ID,
            'HTTP_X_FACTORY_TIMESTAMP' => $raw,
            'HTTP_X_FACTORY_NONCE' => $nonce,
            'HTTP_X_FACTORY_SIGNATURE' => hash_hmac('sha256', $canonical, self::KEY),
        ];
        if (strtoupper($method) !== 'GET') {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey
                ?? 'idem-'.substr(hash('sha256', $nonce), 0, 32);
        }

        return $headers;
    }
}

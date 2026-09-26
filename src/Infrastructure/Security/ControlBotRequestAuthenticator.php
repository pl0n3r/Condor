<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Http\RequestIdSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class ControlBotRequestAuthenticator
{
    private const MAX_SKEW_SECONDS = 300;
    private const NONCE_TTL_SECONDS = 600;
    private const MIN_KEY_BYTES = 32;
    private const IDEMPOTENCY_RETENTION_SECONDS = 86400;
    private const IDEMPOTENCY_PRODUCT = 'condor';

    public function __construct(
        private Connection $connection,
        private RateLimiterFactory $controlBotApiLimiter,
    ) {
    }

    public function authenticate(Request $request): void
    {
        $configuredKeyId = (string) getenv('CONDOR_CONTROLBOT_KEY_ID');
        $key = (string) getenv('CONDOR_CONTROLBOT_KEY');
        $allowed = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) getenv('CONDOR_CONTROLBOT_ALLOWED_IPS')),
        )));
        if ($configuredKeyId === '' || strlen($key) < self::MIN_KEY_BYTES || $allowed === []) {
            throw new NotFoundHttpException();
        }
        if (!$request->isSecure()) {
            throw new AccessDeniedHttpException('ControlBot requiere HTTPS.');
        }

        $keyId = $request->headers->get('X-Factory-Key-Id', '');
        $timestampRaw = $request->headers->get('X-Factory-Timestamp', '');
        $nonce = $request->headers->get('X-Factory-Nonce', '');
        $signature = strtolower($request->headers->get('X-Factory-Signature', ''));

        if (
            preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $keyId) !== 1
            || !hash_equals($configuredKeyId, $keyId)
            || !ctype_digit($timestampRaw)
        ) {
            throw new UnauthorizedHttpException('HMAC', 'Credencial ControlBot inválida.');
        }

        $timestamp = (int) $timestampRaw;
        if (abs(time() - $timestamp) > self::MAX_SKEW_SECONDS) {
            throw new UnauthorizedHttpException('HMAC', 'Timestamp ControlBot inválido.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{22,128}$/D', $nonce) !== 1) {
            throw new UnauthorizedHttpException('HMAC', 'Nonce ControlBot inválido.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            throw new UnauthorizedHttpException('HMAC', 'Firma ControlBot inválida.');
        }

        $ip = (string) $request->getClientIp();
        if ($ip === '' || !in_array($ip, $allowed, true)) {
            throw new AccessDeniedHttpException('Origen ControlBot no autorizado.');
        }
        $limit = $this->controlBotApiLimiter->create($keyId.':'.$ip)->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Límite de ControlBot excedido.',
            );
        }

        $canonical = ControlBotCanonicalRequest::canonical(
            $request,
            $keyId,
            $timestampRaw,
            $nonce,
        );
        if (!hash_equals(hash_hmac('sha256', $canonical, $key), $signature)) {
            throw new UnauthorizedHttpException('HMAC', 'Firma ControlBot inválida.');
        }

        $nonceHash = hash('sha256', $nonce);
        $this->connection->executeStatement(
            'DELETE FROM condor_controlbot_nonce WHERE expires_at < UTC_TIMESTAMP()',
        );
        try {
            $this->connection->insert('condor_controlbot_nonce', [
                'key_id' => $keyId,
                'nonce_hash' => $nonceHash,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + self::NONCE_TTL_SECONDS),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Nonce ControlBot ya utilizado.');
        }
    }

    public function requireIdempotencyKey(Request $request): void
    {
        $this->idempotencyKey($request);
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}|null
     */
    public function beginIdempotentMutation(
        Request $request,
        string $action,
        string $target,
    ): ?array {
        $idempotencyKey = $this->idempotencyKey($request);
        $keyId = $request->headers->get('X-Factory-Key-Id', '');
        $keyHash = hash('sha256', $idempotencyKey);
        $fingerprint = $this->mutationFingerprint($request);
        $now = time();

        $this->connection->executeStatement(
            'DELETE FROM condor_controlbot_idempotency WHERE expires_at < UTC_TIMESTAMP()',
        );

        try {
            $this->connection->insert('condor_controlbot_idempotency', [
                'product_key' => self::IDEMPOTENCY_PRODUCT,
                'actor_key_id' => $keyId,
                'action' => $action,
                'target_key' => $target,
                'idempotency_key_hash' => $keyHash,
                'request_fingerprint' => $fingerprint,
                'response_status' => null,
                'response_body' => null,
                'created_at' => gmdate('Y-m-d H:i:s', $now),
                'expires_at' => gmdate(
                    'Y-m-d H:i:s',
                    $now + self::IDEMPOTENCY_RETENTION_SECONDS,
                ),
            ]);

            return null;
        } catch (UniqueConstraintViolationException) {
            $row = $this->connection->fetchAssociative(
                'SELECT request_fingerprint, response_status, response_body '
                .'FROM condor_controlbot_idempotency '
                .'WHERE product_key = :product '
                .'AND actor_key_id = :actor '
                .'AND action = :action '
                .'AND target_key = :target '
                .'AND idempotency_key_hash = :key_hash '
                .'FOR UPDATE',
                [
                    'product' => self::IDEMPOTENCY_PRODUCT,
                    'actor' => $keyId,
                    'action' => $action,
                    'target' => $target,
                    'key_hash' => $keyHash,
                ],
            );
            if ($row === false) {
                throw new ConflictHttpException('Estado de idempotencia no disponible.');
            }
            if (!hash_equals((string) $row['request_fingerprint'], $fingerprint)) {
                throw new ConflictHttpException(
                    'Idempotency-Key reutilizada con otra petición.',
                );
            }
            if ($row['response_status'] === null || $row['response_body'] === null) {
                throw new ConflictHttpException('Mutación idempotente todavía en progreso.');
            }

            $payload = json_decode(
                (string) $row['response_body'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            if (!is_array($payload)) {
                throw new ConflictHttpException('Resultado idempotente inválido.');
            }

            return [
                'status' => (int) $row['response_status'],
                'payload' => $payload,
            ];
        }
    }

    /** @param array<string, mixed> $payload */
    public function completeIdempotentMutation(
        Request $request,
        string $action,
        string $target,
        int $status,
        array $payload,
    ): void {
        $idempotencyKey = $this->idempotencyKey($request);
        $updated = $this->connection->executeStatement(
            'UPDATE condor_controlbot_idempotency '
            .'SET response_status = :status, response_body = :body '
            .'WHERE product_key = :product '
            .'AND actor_key_id = :actor '
            .'AND action = :action '
            .'AND target_key = :target '
            .'AND idempotency_key_hash = :key_hash '
            .'AND request_fingerprint = :fingerprint',
            [
                'status' => $status,
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
                'product' => self::IDEMPOTENCY_PRODUCT,
                'actor' => $request->headers->get('X-Factory-Key-Id', ''),
                'action' => $action,
                'target' => $target,
                'key_hash' => hash('sha256', $idempotencyKey),
                'fingerprint' => $this->mutationFingerprint($request),
            ],
        );
        if ($updated !== 1) {
            throw new ConflictHttpException('No fue posible consolidar la mutación idempotente.');
        }
    }

    private function idempotencyKey(Request $request): string
    {
        $value = $request->headers->get('Idempotency-Key', '');
        if (
            $value === ''
            || strlen($value) > 200
            || trim($value) !== $value
            || preg_match('/[\\x00-\\x1F\\x7F]/D', $value) === 1
        ) {
            throw new UnprocessableEntityHttpException(
                'Idempotency-Key requerida para mutaciones ControlBot.',
            );
        }

        return $value;
    }

    private function mutationFingerprint(Request $request): string
    {
        $material = implode("\n", [
            strtoupper($request->getMethod()),
            ControlBotCanonicalRequest::pathWithSortedQuery($request),
            hash('sha256', (string) $request->getContent()),
        ]);

        return hash('sha256', $material);
    }

    /** @param array<string, scalar|null> $context */
    public function audit(
        Request $request,
        string $action,
        ?string $targetStaffId = null,
        array $context = [],
    ): void {
        $keyId = $request->headers->get('X-Factory-Key-Id', '');
        $nonce = $request->headers->get('X-Factory-Nonce', '');
        $requestId = $request->attributes->get(RequestIdSubscriber::ATTRIBUTE);
        $result = (string) ($context['result'] ?? 'unknown');
        unset($context['result']);

        $this->connection->insert('condor_controlbot_audit', [
            'action' => $action,
            'actor_key_id' => $keyId,
            'target_staff_user_id' => $targetStaffId,
            'result' => $result,
            'request_id' => is_string($requestId) ? $requestId : 'unavailable',
            'request_nonce_hash' => $nonce === '' ? null : hash('sha256', $nonce),
            'request_ip' => (string) $request->getClientIp(),
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

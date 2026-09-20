<?php

declare(strict_types=1);

namespace App\Domain\Observability\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_error_incident')]
#[ORM\Index(name: 'idx_error_incident_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_error_incident_fingerprint', columns: ['fingerprint'])]
class ErrorIncident
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'request_id', type: 'string', length: 26)]
    private string $requestId;

    #[ORM\Column(type: 'smallint')]
    private int $status;

    #[ORM\Column(type: 'string', length: 12)]
    private string $method;

    #[ORM\Column(name: 'route_name', type: 'string', length: 190, nullable: true)]
    private ?string $routeName;

    #[ORM\Column(name: 'exception_class', type: 'string', length: 255)]
    private string $exceptionClass;

    #[ORM\Column(type: 'string', length: 1200)]
    private string $message;

    #[ORM\Column(type: 'string', length: 64)]
    private string $fingerprint;

    #[ORM\Column(type: 'string', length: 32)]
    private string $version;

    #[ORM\Column(name: 'release_sha', type: 'string', length: 40)]
    private string $releaseSha;

    /** @var list<array{file: string, line: int|null, call: string}> */
    #[ORM\Column(type: 'json')]
    private array $trace;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    /**
     * @param list<array{file: string, line: int|null, call: string}> $trace
     */
    public function __construct(
        string $requestId,
        int $status,
        string $method,
        ?string $routeName,
        string $exceptionClass,
        string $message,
        string $fingerprint,
        string $version,
        string $releaseSha,
        array $trace,
    ) {
        $this->id = UlidFactory::new();
        $this->requestId = $requestId;
        $this->status = $status;
        $this->method = substr($method, 0, 12);
        $this->routeName = $routeName;
        $this->exceptionClass = substr($exceptionClass, 0, 255);
        $this->message = substr($message, 0, 1200);
        $this->fingerprint = $fingerprint;
        $this->version = substr($version, 0, 32);
        $this->releaseSha = substr($releaseSha, 0, 40);
        $this->trace = array_slice($trace, 0, 12);
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function routeName(): ?string
    {
        return $this->routeName;
    }

    public function exceptionClass(): string
    {
        return $this->exceptionClass;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function releaseSha(): string
    {
        return $this->releaseSha;
    }

    /** @return list<array{file: string, line: int|null, call: string}> */
    public function trace(): array
    {
        return $this->trace;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

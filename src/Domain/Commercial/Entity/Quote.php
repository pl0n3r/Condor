<?php
declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_quote')]
final class Quote
{
    #[ORM\Id, ORM\Column(type: 'string', length: 26)]
    private string $id;
    #[ORM\ManyToOne(targetEntity: PlanVersion::class)]
    #[ORM\JoinColumn(name: 'plan_version_id', referencedColumnName: 'id', nullable: false)]
    private PlanVersion $planVersion;
    #[ORM\ManyToOne(targetEntity: Vertical::class)]
    #[ORM\JoinColumn(name: 'vertical_id', referencedColumnName: 'id', nullable: false)]
    private Vertical $vertical;
    #[ORM\Column(type: 'string', length: 12)]
    private string $cycle;
    /** @var array<string,int> */
    #[ORM\Column(type: 'json')]
    private array $quantities;
    /** @var list<string> */
    #[ORM\Column(name: 'add_ons', type: 'json')]
    private array $addOns;
    #[ORM\Column(name: 'base_amount', type: 'integer', nullable: true)]
    private ?int $baseAmount;
    #[ORM\Column(name: 'addon_amount', type: 'integer', nullable: true)]
    private ?int $addOnAmount;
    #[ORM\Column(name: 'total_amount', type: 'integer', nullable: true)]
    private ?int $totalAmount;
    #[ORM\Column(name: 'proposal_required', type: 'boolean')]
    private bool $proposalRequired;
    #[ORM\Column(name: 'tax_policy', type: 'string', length: 80, nullable: true)]
    private ?string $taxPolicy = null;
    #[ORM\Column(name: 'tax_amount', type: 'integer', nullable: true)]
    private ?int $taxAmount = null;
    #[ORM\Column(type: 'string', length: 16)]
    private string $status = 'draft';
    #[ORM\Column(name: 'valid_until', type: 'datetime_immutable')]
    private DateTimeImmutable $validUntil;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @param array<string,int> $quantities @param list<string> $addOns */
    public function __construct(
        PlanVersion $planVersion,
        Vertical $vertical,
        string $cycle,
        array $quantities,
        array $addOns,
        ?int $baseAmount,
        ?int $addOnAmount,
        ?int $totalAmount,
        bool $proposalRequired,
        DateTimeImmutable $createdAt,
    ) {
        if (!in_array($cycle, ['monthly', 'annual'], true)) {
            throw new DomainException('Ciclo comercial inválido.');
        }
        ksort($quantities);
        sort($addOns);
        $this->id = UlidFactory::new();
        $this->planVersion = $planVersion;
        $this->vertical = $vertical;
        $this->cycle = $cycle;
        $this->quantities = $quantities;
        $this->addOns = array_values(array_unique($addOns));
        $this->baseAmount = $baseAmount;
        $this->addOnAmount = $addOnAmount;
        $this->totalAmount = $totalAmount;
        $this->proposalRequired = $proposalRequired;
        $this->createdAt = $createdAt;
        $this->validUntil = $createdAt->modify('+30 days');
    }

    public function id(): string { return $this->id; }
    public function planVersion(): PlanVersion { return $this->planVersion; }
    public function vertical(): Vertical { return $this->vertical; }
    /** @return array<string,int> */
    public function quantities(): array { return $this->quantities; }
    /** @return list<string> */
    public function addOns(): array { return $this->addOns; }
    public function totalAmount(): ?int { return $this->totalAmount; }
    public function proposalRequired(): bool { return $this->proposalRequired; }
    public function status(): string { return $this->status; }
}

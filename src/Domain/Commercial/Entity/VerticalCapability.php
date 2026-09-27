<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Shared\Id\UlidFactory;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_vertical_capability')]
#[ORM\UniqueConstraint(
    name: 'uniq_commercial_vertical_capability_key',
    columns: ['relation_key'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_commercial_vertical_capability_pair',
    columns: ['vertical_id', 'capability_id'],
)]
#[ORM\Index(
    name: 'idx_commercial_vertical_capability_priority',
    columns: ['vertical_id', 'priority'],
)]
final class VerticalCapability
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'relation_key', type: 'string', length: 140)]
    private string $key;

    #[ORM\ManyToOne(targetEntity: Vertical::class)]
    #[ORM\JoinColumn(
        name: 'vertical_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Vertical $vertical;

    #[ORM\ManyToOne(targetEntity: Capability::class)]
    #[ORM\JoinColumn(
        name: 'capability_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Capability $capability;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $priority;

    public function __construct(
        Vertical $vertical,
        Capability $capability,
        int $priority,
    ) {
        $this->assertPriority($priority);
        $this->id = UlidFactory::new();
        $this->key = self::keyFor($vertical, $capability);
        $this->vertical = $vertical;
        $this->capability = $capability;
        $this->priority = $priority;
    }

    public static function keyFor(
        Vertical|string $vertical,
        Capability|string $capability,
    ): string {
        $verticalKey = $vertical instanceof Vertical ? $vertical->key() : $vertical;
        $capabilityKey = $capability instanceof Capability ? $capability->key() : $capability;

        return strtolower(trim($verticalKey)).'--'.strtolower(trim($capabilityKey));
    }

    public function id(): string
    {
        return $this->id;
    }
    public function key(): string
    {
        return $this->key;
    }
    public function vertical(): Vertical
    {
        return $this->vertical;
    }
    public function capability(): Capability
    {
        return $this->capability;
    }
    public function priority(): int
    {
        return $this->priority;
    }

    public function reorder(int $priority): void
    {
        $this->assertPriority($priority);
        $this->priority = $priority;
    }

    private function assertPriority(int $priority): void
    {
        if ($priority < 1 || $priority > 1000) {
            throw new DomainException('Prioridad de capability por vertical inválida.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Labels;

use DomainException;

/** A print-only template; dimensions are fixed to avoid injecting CSS. */
final readonly class LabelTemplate
{
    public function __construct(
        public string $name,
        public int $widthMillimeters,
        public int $heightMillimeters,
    ) {
        if (trim($name) === '' || strlen($name) > 80
            || $widthMillimeters < 20 || $widthMillimeters > 150
            || $heightMillimeters < 15 || $heightMillimeters > 100
        ) {
            throw new DomainException('Plantilla de etiqueta inválida.');
        }
    }

    public static function standard(): self
    {
        return new self('Estándar', 60, 40);
    }
}

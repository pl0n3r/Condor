<?php

declare(strict_types=1);

namespace App\Shared\Id;

use Symfony\Component\Uid\Ulid;

final class UlidFactory
{
    public static function new(): string
    {
        return (string) new Ulid();
    }
}

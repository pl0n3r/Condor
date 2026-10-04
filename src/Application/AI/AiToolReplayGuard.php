<?php

declare(strict_types=1);

namespace App\Application\AI;

use DomainException;

final class AiToolReplayGuard
{
    private const MAX_CLAIMS = 128;

    /** @var array<string, true> */
    private array $claims = [];

    public function claim(AiToolReplayKey $key): bool
    {
        $value = $key->value();
        if (preg_match('/^replay:[a-f0-9]{64}$/D', $value) !== 1) {
            throw new DomainException('Replay key de IA inválida.');
        }

        if (isset($this->claims[$value])) {
            return false;
        }

        if (count($this->claims) >= self::MAX_CLAIMS) {
            throw new DomainException('Capacidad local de replay agotada.');
        }

        $this->claims[$value] = true;

        return true;
    }
}

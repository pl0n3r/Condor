<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

final readonly class OnboardingResult
{
    public function __construct(
        public string $tenantId,
        public string $tenantSlug,
        public string $branchId,
        public string $ownerUserId,
    ) {
    }
}

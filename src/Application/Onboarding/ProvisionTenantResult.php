<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;

final readonly class ProvisionTenantResult
{
    public function __construct(
        public Tenant $tenant,
        public LegalEntity $legalEntity,
        public Branch $branch,
    ) {
    }
}

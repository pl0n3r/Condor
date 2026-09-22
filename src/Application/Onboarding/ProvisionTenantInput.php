<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProvisionTenantInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 160)]
        public string $tenantName,

        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
        #[Assert\Length(max: 120)]
        public string $tenantSlug,

        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $legalName,

        #[Assert\Length(max: 32)]
        public ?string $nit,

        #[Assert\NotBlank]
        #[Assert\Length(max: 160)]
        public string $branchName,
    ) {
    }
}

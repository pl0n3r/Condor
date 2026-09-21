<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Onboarding\ProvisionTenantResult;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\User;

final readonly class PlatformTenantInvitationResult
{
    public function __construct(
        public ProvisionTenantResult $provisioning,
        public User $owner,
        public AccountInvitation $invitation,
        public string $rawToken,
    ) {
    }
}

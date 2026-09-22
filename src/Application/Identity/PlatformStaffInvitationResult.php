<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\User;

final readonly class PlatformStaffInvitationResult
{
    public function __construct(
        public User $user,
        public AccountInvitation $invitation,
        public string $rawToken,
    ) {
    }
}

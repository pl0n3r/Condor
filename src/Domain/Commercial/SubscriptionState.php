<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

enum SubscriptionState: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case GracePeriod = 'grace_period';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}

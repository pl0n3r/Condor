<?php

declare(strict_types=1);

namespace App\Application\Commercial;

interface BillingAdapter
{
    public function submit(BillingRequest $request): BillingResult;
}

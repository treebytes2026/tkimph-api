<?php

namespace App\Http\Controllers\Concerns;

use App\Support\PlatformPricing;

trait InteractsWithSettlementSettings
{
    protected function ensureSettlementsEnabled(): void
    {
        abort_unless(PlatformPricing::settlementsEnabled(), 404, 'Settlements are disabled.');
    }
}

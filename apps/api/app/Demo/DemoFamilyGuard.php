<?php

namespace App\Demo;

use RuntimeException;

final class DemoFamilyGuard
{
    public function assertEnabled(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Demo-family tooling is available only when APP_ENV=local.');
        }

        if (config('demo-family.enabled') !== true) {
            throw new RuntimeException('Demo-family tooling requires FAMBAM_DEMO_SEEDING_ENABLED=true.');
        }
    }
}

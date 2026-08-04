<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FamilySpaceCreationCapability;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GrantFamilySpaceCreation extends Command
{
    protected $signature = 'fambam:grant-family-space-creation
                            {email : Email address of the account}
                            {--operator= : Stable operator reference recorded in the security audit}';

    protected $description = 'Grant the narrow platform capability to create new Family Spaces';

    public function handle(FamilySpaceCreationCapability $capability): int
    {
        return $this->updateCapability($capability, true);
    }

    protected function updateCapability(FamilySpaceCreationCapability $capability, bool $enabled): int
    {
        $operator = trim((string) $this->option('operator'));

        if ($operator === '') {
            $this->components->error('An operator reference is required for audit attribution.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('No account was found for that email address.');

            return self::FAILURE;
        }

        $capability->set($user, $enabled, $operator);
        $this->components->info($enabled
            ? 'Family Space creation capability granted.'
            : 'Family Space creation capability revoked.');

        return self::SUCCESS;
    }
}

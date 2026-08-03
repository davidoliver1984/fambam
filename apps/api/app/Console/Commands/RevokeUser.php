<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RevokeUserAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RevokeUser extends Command
{
    protected $signature = 'fambam:revoke-user
                            {email : Email address of the account to revoke}
                            {--operator= : Stable operator reference recorded in the security audit}
                            {--force : Revoke without an interactive confirmation}';

    protected $description = 'Revoke an account and invalidate all sessions and remembered sign-ins';

    public function handle(RevokeUserAccess $revokeAccess): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $operator = trim((string) $this->option('operator'));

        if ($operator === '') {
            $this->components->error('An operator reference is required for audit attribution.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error('No account was found for that email address.');

            return self::FAILURE;
        }

        if ($user->revoked_at !== null) {
            $this->components->warn('That account is already revoked.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Revoke {$email} and end every active session?")) {
            $this->components->info('No changes were made.');

            return self::SUCCESS;
        }

        $revokeAccess->handle(
            $user,
            'operator_revoked',
            revokeAccount: true,
            auditMetadata: [
                'actor_type' => 'console_operator',
                'operator_reference' => $operator,
            ],
        );
        $this->components->info('The account was revoked and all access was invalidated.');

        return self::SUCCESS;
    }
}

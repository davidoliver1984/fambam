<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class BootstrapUser extends Command
{
    protected $signature = 'fambam:bootstrap-user
                            {email : Email address for the first account}
                            {--name= : Display name}
                            {--timezone=UTC : IANA timezone}';

    protected $description = 'Create the first verified invitation-capable account';

    public function handle(AuditRecorder $audit): int
    {
        if (User::query()->exists()) {
            $this->components->error('The bootstrap command only works before the first account exists.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Display name')));
        $timezone = (string) $this->option('timezone');
        $password = (string) $this->secret('Password (minimum 15 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'timezone' => $timezone,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'password' => ['required', 'string', 'max:255', Password::min(15), 'confirmed'],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'timezone' => $timezone,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'can_invite' => true,
        ])->save();

        $audit->record('account.bootstrapped', $user, metadata: ['can_invite' => true]);
        $this->components->info('The first account was created successfully.');

        return self::SUCCESS;
    }
}

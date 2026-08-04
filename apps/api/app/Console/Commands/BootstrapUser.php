<?php

namespace App\Console\Commands;

use App\Enums\FamilySpaceRole;
use App\Enums\FamilySpaceStatus;
use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Tenancy\DatabaseTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class BootstrapUser extends Command
{
    protected $signature = 'fambam:bootstrap-user
                            {email : Email address for the first account}
                            {--name= : Display name}
                            {--timezone=UTC : IANA timezone}
                            {--family-name=Family Archive : Name of the first Family Space}
                            {--family-slug=family-archive : URL slug of the first Family Space}';

    protected $description = 'Create the first verified account, Family Space and Owner membership';

    public function handle(AuditRecorder $audit, DatabaseTenantContext $databaseTenantContext): int
    {
        if (User::query()->exists()) {
            $this->components->error('The bootstrap command only works before the first account exists.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Display name')));
        $timezone = (string) $this->option('timezone');
        $familyName = trim((string) $this->option('family-name'));
        $familySlug = Str::lower(trim((string) $this->option('family-slug')));
        $password = (string) $this->secret('Password (minimum 15 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'timezone' => $timezone,
            'family_name' => $familyName,
            'family_slug' => $familySlug,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'family_name' => ['required', 'string', 'max:255'],
            'family_slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        DB::transaction(function () use (
            $audit,
            $name,
            $email,
            $timezone,
            $password,
            $familyName,
            $familySlug,
            $databaseTenantContext,
        ): void {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'timezone' => $timezone,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'can_create_family_spaces' => false,
            ])->save();

            $familySpaceId = (string) Str::ulid();
            $databaseTenantContext->establishUser($user);
            $databaseTenantContext->establishFamilySpace(
                $familySpaceId,
                authoritativeOperation: 'family_space_creation',
            );
            $familySpace = FamilySpace::query()->create([
                'id' => $familySpaceId,
                'name' => $familyName,
                'slug' => $familySlug,
                'status' => FamilySpaceStatus::Active,
            ]);
            $membership = $familySpace->memberships()->create([
                'user_id' => $user->id,
                'role' => FamilySpaceRole::Owner,
                'state' => MembershipState::Active,
            ]);

            $audit->record('account.bootstrapped', $user, metadata: [
                'family_space_id' => $familySpace->id,
                'owner_membership_id' => $membership->id,
                'can_create_family_spaces' => false,
            ]);
            $audit->record('family_space.created', $familySpace, $user, metadata: [
                'initial_owner_membership_id' => $membership->id,
                'bootstrap' => true,
            ]);
        });
        $this->components->info('The first account and Family Space were created successfully.');

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_registration_route_does_not_exist(): void
    {
        $this->postJson('/register', [
            'name' => 'Uninvited Person',
            'email' => 'uninvited@example.test',
            'password' => 'not-allowed',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_user_can_log_in_read_their_profile_and_log_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.timezone', 'UTC');

        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_invalid_credentials_do_not_create_a_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_current_user_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->patchJson('/api/user/profile', [])->assertUnauthorized();
    }

    public function test_user_can_update_name_and_iana_timezone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/user/profile', [
                'name' => 'David Oliver',
                'timezone' => 'Europe/London',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'David Oliver')
            ->assertJsonPath('data.timezone', 'Europe/London');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'David Oliver',
            'timezone' => 'Europe/London',
        ]);

        $this->actingAs($user)
            ->patchJson('/api/user/profile', [
                'name' => 'David Oliver',
                'timezone' => 'Not/A-Timezone',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    }

    public function test_password_reset_request_is_enumeration_safe(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $knownAccount = $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $unknownAccount = $this->postJson('/forgot-password', ['email' => 'missing@example.test'])->assertOk();

        $this->assertSame($knownAccount->json(), $unknownAccount->json());

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_user_can_reset_their_password(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('replacement-password', $user->refresh()->password));
    }

    public function test_email_verification_endpoint_marks_account_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(10),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->getJson($url)->assertNoContent();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_csrf_cookie_and_credentialed_cors_are_configured_for_the_spa(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');

        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertSame(['http://localhost:3010'], config('cors.allowed_origins'));
    }
}

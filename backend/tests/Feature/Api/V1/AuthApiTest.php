<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class AuthApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_login_me_and_logout_using_bearer_token(): void
    {
        $user = User::factory()->create([
            'password' => 'password-rahasia-123',
            'is_active' => true,
        ]);

        $user->assignRole('staff_hr');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-rahasia-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.user.roles.0',
                'staff_hr'
            );

        $token = $response->json('data.token');

        $this->assertIsString($token);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Pastikan token benar-benar terhapus dari database.
        $this->assertNull(
            PersonalAccessToken::findToken($token)
        );

        // Bersihkan guard yang tersimpan selama feature test.
        // Ini bukan kode yang perlu ditambahkan ke controller logout.
        Auth::forgetGuards();

        // Token yang sudah dicabut harus ditolak.
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->assignRole('staff_hr');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-salah',
        ])->assertUnauthorized();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
        ]);

        $user->assignRole('hr');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();
    }
}

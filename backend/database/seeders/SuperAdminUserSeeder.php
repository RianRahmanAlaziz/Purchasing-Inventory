<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class SuperAdminUserSeeder extends Seeder
{
    private const ROLE_NAME = 'super_admin';

    public function run(): void
    {
        $email = $this->command?->option('no-interaction')
            ? ''
            : Str::lower(trim((string) $this->command?->ask('Email Super Admin (kosongkan untuk melewati)')));

        if ($email === '') {
            $this->command?->info('Pembuatan akun Super Admin dilewati.');

            return;
        }

        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();

        $role = Role::query()
            ->where('name', self::ROLE_NAME)
            ->where('guard_name', 'web')
            ->first();

        if ($role === null) {
            throw new RuntimeException(
                'Role super_admin belum tersedia. Jalankan RolePermissionSeeder terlebih dahulu.'
            );
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $this->reportExistingAdmin($existing, $role);

            return;
        }

        if (! $role->checkPermissionTo('roles.manage')) {
            throw new RuntimeException('Role super_admin belum memiliki izin roles.manage.');
        }

        $name = trim((string) $this->command?->ask('Nama Super Admin', 'Super Administrator'));
        $password = (string) $this->command?->secret('Password Super Admin (minimal 12 karakter)', false);
        $confirmation = (string) $this->command?->secret('Ulangi password Super Admin', false);

        Validator::make(
            ['name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ],
        )->validate();

        $existing = DB::transaction(function () use ($role, $name, $email, $password): ?User {
            $existing = User::query()->where('email', $email)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
            $user->assignRole($role);

            return null;
        }, attempts: 3);

        if ($existing !== null) {
            $this->reportExistingAdmin($existing, $role);

            return;
        }

        $this->command?->info('Akun Super Admin berhasil dibuat.');
    }

    private function reportExistingAdmin(User $user, Role $role): void
    {
        if (! $user->hasRole($role)) {
            throw new RuntimeException('Email sudah digunakan oleh akun tanpa role super_admin.');
        }

        $this->command?->info('Akun Super Admin sudah ada.');
    }
}

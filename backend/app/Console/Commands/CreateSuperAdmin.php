<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class CreateSuperAdmin extends Command
{
    protected $signature = 'users:create-super-admin';

    protected $description = 'Buat akun Super Admin pertama melalui terminal';

    public function handle(): int
    {
        if (! Role::query()
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->exists()) {
            $this->error(
                'Role belum tersedia. Jalankan RolePermissionSeeder terlebih dahulu.'
            );

            return self::FAILURE;
        }

        $name = trim(
            (string) $this->ask('Nama lengkap')
        );

        $email = Str::lower(trim(
            (string) $this->ask('Email')
        ));

        $password = (string) $this->secret(
            'Password (minimal 12 karakter)'
        );

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    'unique:users,email',
                ],
                'password' => [
                    'required',
                    'string',
                    'min:12',
                ],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use (
            $name,
            $email,
            $password
        ): void {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);

            $user->assignRole('super_admin');
        });

        $this->info('Akun Super Admin berhasil dibuat.');

        return self::SUCCESS;
    }
}

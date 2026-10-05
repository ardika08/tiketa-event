<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Buat / perbarui akun Admin Platform saja (untuk produksi).
     * Email & password dapat diatur lewat env ADMIN_EMAIL / ADMIN_PASSWORD.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@nontix.id');
        $password = env('ADMIN_PASSWORD', 'admin123');

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'nama' => 'Admin Nontix',
                'password' => $password,
                'peran' => Role::ADMIN,
                'status' => UserStatus::ACTIVE,
            ],
        );

        $this->command->info("Admin siap: {$admin->email}");
        $this->command->warn('Segera ganti password admin setelah login pertama.');
    }
}

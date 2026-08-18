<?php
namespace Database\Seeders;
use App\Models\User;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
class DatabaseSeeder extends Seeder {
    public function run(): void {
        SystemSetting::create([
            'tv_idle_seconds' => 30,
            'shrink_timeout' => 15,
            'collapse_timeout' => 30,
            'periodic_return_timer' => 0,
            'periodic_return_mode' => 'full_queue',
            'ads_interval' => 10,
            'media_mode' => 'ads'
        ]);
        User::create([
            'username' => 'admin',
            'password_hash' => Hash::make('admin123'),
            'role' => 'Admin'
        ]);
    }
}

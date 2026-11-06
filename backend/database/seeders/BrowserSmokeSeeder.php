<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Synthetic credentials for disposable browser CI only.
 * Refuses to run in local, staging, and production environments.
 */
class BrowserSmokeSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('testing')) {
            throw new RuntimeException('BrowserSmokeSeeder runs only under APP_ENV=testing');
        }

        User::updateOrCreate(
            ['email' => 'browser.owner@example.test'],
            [
                'name' => 'مالك تجريبي للمتصفح',
                'password' => Hash::make('ci-browser-password-2026'),
            ]
        );
    }
}

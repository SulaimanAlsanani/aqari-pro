<?php
/**
 * CI-only synthetic identity fixture, streamed into a private Docker container.
 * This file is not bundled into the production application image.
 */
if (getenv('AQARI_E2E_ONLY') !== '1' || getenv('APP_ENV') !== 'staging') {
    fwrite(STDERR, "Synthetic fixture may only run in the isolated staging CI service.\n");
    exit(1);
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Owner;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

$password = getenv('AQARI_E2E_PASSWORD');
if (!$password || strlen($password) < 12) {
    fwrite(STDERR, "Test password is absent or too short.\n");
    exit(1);
}

DB::transaction(function () use ($password): void {
    $ownerUser = User::query()->create([
        'name' => 'Staging E2E Owner',
        'email' => 'owner.e2e@example.test',
        'password' => Hash::make($password),
    ]);
    Owner::query()->forceCreate([
        'user_id' => $ownerUser->id,
        'name' => 'Staging E2E Owner',
    ]);

    $otherUser = User::query()->create([
        'name' => 'Other E2E Owner',
        'email' => 'other.e2e@example.test',
        'password' => Hash::make($password.'different'),
    ]);
    $otherOwner = Owner::query()->forceCreate([
        'user_id' => $otherUser->id,
        'name' => 'Other E2E Owner',
    ]);
    // Always created before the first owner's first property: id 1 in a fresh CI DB.
    $otherOwner->properties()->create([
        'name' => 'CROSS_OWNER_PRIVATE_PROPERTY',
        'city' => 'Jeddah',
    ]);
});
echo "Two synthetic owners and a private cross-owner property seeded.\n";

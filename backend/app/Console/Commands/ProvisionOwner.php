<?php

namespace App\Console\Commands;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProvisionOwner extends Command
{
    protected $signature = 'aqari:provision-owner
        {email : New owner login email}
        {--name= : Full name of the owner}';

    protected $description = 'Interactively create a first-party owner account (staging operator only).';

    public function handle(): int
    {
        // Never allow passwords through command arguments, .env or public API.
        // Operators must use an interactive terminal on a restricted server.
        if (! $this->input->isInteractive()) {
            $this->error('Owner creation requires an interactive private terminal.');
            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Full name')));
        $email = strtolower(trim((string) $this->argument('email')));

        $fields = Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
        ]);
        if ($fields->fails()) {
            $this->error($fields->errors()->first());
            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (at least 12 characters)');
        $confirm = (string) $this->secret('Confirm new password');
        if (strlen($password) < 12 || $password !== $confirm) {
            $this->error('Password must contain at least 12 characters and confirmation must match.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password): void {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            Owner::query()->forceCreate([
                'user_id' => $user->id,
                'name' => $name,
            ]);
        });

        $this->info('Owner account created. Open the staging HTTPS login page.');
        return self::SUCCESS;
    }
}

<?php
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateOwner extends Command
{
    protected $signature = 'owner:create {email : Login email} {name : Owner full name}';
    protected $description = 'Create a property-owner account with a concealed password prompt';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));

        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:255']]
        );
        if ($validator->fails()) {
            $this->error('Invalid owner name or email.');
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('An account already exists for this email.');
            return self::FAILURE;
        }

        if (!$this->input->isInteractive()) {
            $this->error('Interactive terminal required for secure password entry. No --password option is accepted.');
            return self::FAILURE;
        }

        $password = $this->secret('Owner password (at least 12 characters)');
        $confirmation = $this->secret('Confirm owner password');

        if (!is_string($password) || mb_strlen($password) < 12 || $password !== $confirmation) {
            $this->error('Password must be at least 12 characters and match its confirmation.');
            return self::FAILURE;
        }

        // The password is never sent through shell arguments, output, or logs.
        User::create(['email' => $email, 'name' => $name, 'password' => Hash::make($password)]);
        $this->info('Owner account created successfully.');
        return self::SUCCESS;
    }
}

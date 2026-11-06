<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('inspire', function () {
    $this->comment('Aqari Pro API');
})->purpose('Display application information');

Artisan::command('owner:create {email} {name}', function (string $email, string $name) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email address.');
        return 1;
    }
    if (\App\Models\User::where('email',$email)->exists()) {
        $this->error('The owner already exists.');
        return 1;
    }
    $password=$this->secret('Enter a strong owner password (minimum 12 characters)');
    $repeat=$this->secret('Confirm password');
    if (!is_string($password)||mb_strlen($password)<12||$password!==$repeat) {
        $this->error('Passwords do not match or do not meet minimum length.');
        return 1;
    }
    \App\Models\User::create(['name'=>$name,'email'=>$email,'password'=>$password]);
    $this->info('Owner account was created successfully.');
    return 0;
})->purpose('Securely provision an owner account interactively');

<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('marketplace:create-admin {email} {--name=Administrator}', function (string $email) {
    $email = mb_strtolower($email);
    $password = $this->secret('Password (min 12 characters, letters, numbers and symbols)');

    $validator = Validator::make(['email' => $email, 'password' => $password], [
        'email' => ['required', 'email'],
        'password' => ['required', Password::min(12)->letters()->numbers()->symbols()],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $user = User::firstOrNew(['email' => $email]);
    if ($user->exists && ! $this->confirm("{$email} already exists as {$user->role->value}. Make it an administrator and reset its password?")) {
        return 1;
    }

    $user->fill(['name' => $user->name ?? $this->option('name'), 'password' => $password]);
    $user->role = Role::Admin;
    $user->save();

    AuditLog::create(['action' => 'admin.created_via_cli', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id]);

    $this->info("Administrator {$email} is ready.");

    return 0;
})->purpose('Create or promote a marketplace administrator');

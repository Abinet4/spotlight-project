<?php

// Small helper for scripts/try-locally.ps1, so PowerShell does not have to quote PHP code.
//   php try-locally-helper.php user-exists <email>              prints yes or no
//   php try-locally-helper.php demo-admin <email> <password>    creates the demo administrator
// Local use only.

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$backend = dirname(__DIR__).DIRECTORY_SEPARATOR.'backend';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';
$email = mb_strtolower($argv[2] ?? '');

if ($command === 'user-exists') {
    echo User::where('email', $email)->exists() ? 'yes' : 'no';
    exit(0);
}

if ($command === 'demo-admin') {
    if (app()->isProduction()) {
        fwrite(STDERR, "Refusing to add a demo admin in production.\n");
        exit(1);
    }
    if (User::where('email', $email)->exists()) {
        echo "{$email} already exists.\n";
        exit(0);
    }
    $user = new User(['name' => 'Demo Admin', 'email' => $email, 'password' => $argv[3] ?? '']);
    $user->role = Role::Admin;
    $user->save();
    AuditLog::create(['action' => 'admin.created_via_cli', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id]);
    echo "Administrator {$email} is ready.\n";
    exit(0);
}

fwrite(STDERR, "Unknown command.\n");
exit(1);

<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\ProductImage;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\ImageVariants;
use App\Services\PaymentService;
use Database\Seeders\DemoFurnitureArt;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

Artisan::command('marketplace:create-admin {email} {--name=Administrator}
    {--password-from-env : Read the password from the ADMIN_PASSWORD variable instead of asking (deploy scripts)}
    {--if-missing : Only create the first administrator: do nothing if this email has an account or any administrator exists}', function (string $email) {
    $email = mb_strtolower(trim($email));

    // A deploy runs this on every start: once the account exists it is left alone, so a password
    // changed on the site is not reset to the deploy variable. And once there is any administrator,
    // nothing is created: after the admin changes their email on the site, the old deploy variables
    // must not bring back a second admin account.
    if ($this->option('if-missing')) {
        if ($existing = User::where('email', $email)->first()) {
            $this->info("{$email} already has an account ({$existing->role->value}); left unchanged.");

            return 0;
        }
        if (User::where('role', Role::Admin)->exists()) {
            $this->info('An administrator already exists, so no account was created for '.$email.'. ADMIN_EMAIL and ADMIN_PASSWORD can be removed.');

            return 0;
        }
    }

    $password = $this->option('password-from-env')
        ? (string) getenv('ADMIN_PASSWORD')
        : $this->secret('Password (min 12 characters, letters, numbers and symbols)');

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

Artisan::command('marketplace:prepare {--demo : Also add the demo sellers and listings, once (test sites only)}', function () {
    // Sub-cities and categories on the first start only: afterwards the admin may rename or delete them.
    if (! City::exists()) {
        $this->call('db:seed', ['--force' => true]);
    }
    if (! $this->option('demo')) {
        return 0;
    }
    if (! AppServiceProvider::isTestSite()) {
        $this->warn('Demo data is for test sites only, so it was not added (APP_ENV must be local, testing or staging).');

        return 0;
    }
    // Recorded only after the seeder finished, so a start that failed halfway tries again, and a demo
    // listing the admin deleted later does not come back on the next deploy.
    if (AuditLog::where('action', 'demo.seeded')->exists()) {
        $this->info('Demo data was added on an earlier start; skipped.');

        return 0;
    }
    if ((string) getenv('DEMO_USER_PASSWORD') === '') {
        $this->error('Set DEMO_USER_PASSWORD (the password for the demo sellers and customer) to add demo data.');

        return 1;
    }

    $code = $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
    if ($code === 0) {
        AuditLog::create(['action' => 'demo.seeded']);
    }

    return $code;
})->purpose('Add the reference data on a new database, and optionally demo data (used when a server starts)');

Artisan::command('marketplace:demo-pictures', function (ImageVariants $variants) {
    // Only pictures the demo seeder generated (products/{id}/demo-*.jpg); seller photos are never touched.
    $images = ProductImage::query()
        ->where('path', 'like', 'products/%/demo-%')
        ->where('path', 'not like', 'products/%/'.DemoSeeder::ART_PREFIX.'%')
        ->with('product:id,name,color')
        ->orderBy('id')
        ->get();

    $art = new DemoFurnitureArt;
    $redrawn = 0;
    foreach ($images as $image) {
        if (! $image->product || ! preg_match('#^products/\d+/demo-[A-Za-z0-9]+\.jpg$#', $image->path)) {
            continue;
        }
        $new = "products/{$image->product_id}/".DemoSeeder::ART_PREFIX.Str::random(8).'.jpg';
        Storage::disk(ProductImage::ORIGINALS_DISK)->put($new, $art->jpeg($image->product->name, $image->product->color, $image->product_id));
        $old = clone $image;
        $image->forceFill(['path' => $new, 'thumb_path' => null, 'large_path' => null, ...$variants->make($new)])->save();
        $old->deleteFiles();
        $redrawn++;
    }

    $this->info($redrawn ? "Redrew {$redrawn} demo ".Str::plural('picture', $redrawn).'.' : 'Demo pictures are up to date.');

    return 0;
})->purpose('Replace the old plain demo pictures with drawn furniture (demo data only)');

Artisan::command('marketplace:image-variants {--all : Remake the copies of every photo, not only photos without them}', function (ImageVariants $variants) {
    // Safe to run again: by default only photos that have no smaller copies yet are processed.
    $query = ProductImage::query()->orderBy('id');
    if (! $this->option('all')) {
        $query->whereNull('thumb_path');
    }

    $made = 0;
    $failed = [];
    $query->chunkById(100, function ($images) use ($variants, &$made, &$failed) {
        foreach ($images as $image) {
            if ($variants->refresh($image)) {
                $made++;
            } else {
                $failed[] = $image->id;
            }
        }
    });

    $this->info("Made smaller copies of {$made} ".Str::plural('photo', $made).'.');
    if ($failed !== []) {
        // These keep showing the original file; the log says why for each one.
        $this->warn(count($failed).' '.Str::plural('photo', count($failed)).' could not be resized (ids '.implode(', ', $failed).'); they keep using the original. See storage/logs/laravel.log.');
    }

    return 0;
})->purpose('Make the small and large copies of product photos that pages load (for photos uploaded before this existed)');

Artisan::command('marketplace:expire-payments', function (PaymentService $payments) {
    $counts = $payments->expireDue();
    $this->info("Expired {$counts['expired']} unpaid checkout(s); {$counts['paid']} turned out paid; {$counts['skipped']} waiting for the payment provider.");
})->purpose('Cancel online orders whose payment time ran out and release their stock');

// Needs the scheduler running in production: a cron entry for `php artisan schedule:run` every minute.
Schedule::command('marketplace:expire-payments')->everyFiveMinutes()->withoutOverlapping();
// Sign-ins last 30 days; remove the expired ones so a copied old token is gone from the database too.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

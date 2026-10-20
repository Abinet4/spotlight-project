<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step sign-in: a code texted to a verified phone after the password. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Set when the user typed a code texted to `phone`; cleared when the phone changes.
            $t->timestamp('phone_verified_at')->nullable()->after('phone');
            $t->timestamp('two_step_enabled_at')->nullable()->after('phone_verified_at');
        });

        // Codes texted for sign-in or to check a phone. Only hashes are kept.
        Schema::create('sign_in_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('purpose', 16); // sign_in | phone
            $t->string('phone', 20);
            $t->string('code_hash', 64);
            // Sign-in only: what the browser holds between the password and the code (sha256).
            $t->string('challenge_hash', 64)->nullable()->unique();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedTinyInteger('sends')->default(1);
            // Nullable only so older MariaDB does not make a NOT NULL timestamp update itself on every save.
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'purpose']);
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_codes');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['phone_verified_at', 'two_step_enabled_at']);
        });
    }
};

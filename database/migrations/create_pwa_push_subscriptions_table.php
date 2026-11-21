<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config()->string('pwa.push.table', 'pwa_push_subscriptions'), function (Blueprint $table): void {
            $table->id();
            // Owners with UUID or ULID keys: uuidMorphs() / ulidMorphs().
            $table->morphs('subscribable');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config()->string('pwa.push.table', 'pwa_push_subscriptions'));
    }
};

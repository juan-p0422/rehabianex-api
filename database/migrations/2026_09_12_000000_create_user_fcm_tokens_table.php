<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legado local: FCM persiste tokens en Firestore y no usa esta tabla en runtime.
     */
    public function up(): void
    {
        Schema::create('user_fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 128);
            $table->string('fcm_token', 4096)->unique();
            $table->string('platform', 20);
            $table->string('device_id')->nullable()->index();
            $table->string('app_version', 50)->nullable();
            $table->string('timezone', 100)->nullable();
            $table->string('locale', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_fcm_tokens');
    }
};

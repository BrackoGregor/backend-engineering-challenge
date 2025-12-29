<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // 'github' or 'strava'
            $table->json('config')->nullable(); // Additional configuration as JSON
            $table->text('oauth_token')->nullable(); // Encrypted OAuth token (for Strava)
            $table->text('oauth_refresh_token')->nullable(); // Encrypted refresh token (for Strava)
            $table->timestamp('oauth_token_expires_at')->nullable(); // Token expiration
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};


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
        Schema::create('strava_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('data_sources')->onDelete('cascade');
            $table->bigInteger('activity_id')->unique(); // Strava activity ID
            $table->jsonb('raw_data'); // Raw data from Strava API as JSONB
            $table->timestamp('extracted_at');
            $table->timestamps();

            // Indexes for better query performance
            $table->index(['source_id', 'extracted_at']);
            $table->index('activity_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strava_data');
    }
};


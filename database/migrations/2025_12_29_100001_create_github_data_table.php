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
        Schema::create('github_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('data_sources')->onDelete('cascade');
            $table->string('repository_name');
            $table->string('event_type')->nullable(); // 'commit', 'issue', 'pull_request', etc.
            $table->jsonb('raw_data'); // Raw data from GitHub API as JSONB
            $table->timestamp('extracted_at');
            $table->timestamps();

            // Indexes for better query performance
            $table->index(['source_id', 'extracted_at']);
            $table->index('repository_name');
            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_data');
    }
};


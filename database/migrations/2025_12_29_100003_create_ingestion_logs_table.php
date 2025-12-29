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
        Schema::create('ingestion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('data_sources')->onDelete('cascade');
            $table->string('dataset_name'); // Name of the dataset sent to Databox
            $table->integer('rows_sent')->default(0);
            $table->integer('columns_sent')->default(0);
            $table->enum('status', ['success', 'failed', 'partial'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            // Indexes for better query performance
            $table->index(['source_id', 'sent_at']);
            $table->index('status');
            $table->index('dataset_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingestion_logs');
    }
};


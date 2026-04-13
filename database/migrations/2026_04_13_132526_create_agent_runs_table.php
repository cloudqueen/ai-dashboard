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
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_note_id')->nullable()->constrained('vault_notes')->nullOnDelete();
            $table->string('ticket_path')->nullable();
            $table->string('skill');
            $table->string('status')->default('queued')->index();
            $table->text('prompt')->nullable();
            $table->longText('raw_output')->nullable();
            $table->text('summary')->nullable();
            $table->string('output_note_path')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('skill');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};

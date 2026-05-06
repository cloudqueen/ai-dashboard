<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('task');
            $table->string('status')->default('backlog')->index();
            $table->string('priority')->default('medium')->index();
            $table->string('assigned_to')->default('human')->index();
            $table->string('agent_skill')->nullable();
            $table->json('tags')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->integer('postpone_count')->default(0);
            $table->string('emotional_charge')->nullable();
            $table->tinyInteger('system_level')->nullable();
            $table->json('context_links')->nullable();
            $table->json('depends_on')->nullable();
            $table->string('model')->nullable();
            $table->foreignId('routine_id')->nullable()->constrained('routines')->nullOnDelete();
            $table->foreignId('source_vault_note_id')->nullable()->constrained('vault_notes')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};

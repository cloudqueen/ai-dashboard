<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('path');
            $table->string('status')->default('active')->index(); // active|paused|done
            $table->string('default_branch')->default('main');
            $table->string('allowed_tools')->default('all');
            $table->string('model')->nullable();
            $table->integer('max_run_minutes')->default(30);
            $table->integer('max_turns')->default(50);
            $table->boolean('nightly_enabled')->default(true);
            $table->string('nightly_schedule')->default('0 2 * * *');
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('branch_name')->nullable();
            $table->string('worktree_path')->nullable();
            $table->string('status')->default('queued')->index(); // queued|running|completed|failed|timed_out
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('prompt')->nullable();
            $table->longText('raw_output')->nullable();
            $table->text('summary')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('review_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'started_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('routine_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
        Schema::dropIfExists('project_runs');
        Schema::dropIfExists('projects');
    }
};

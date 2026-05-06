<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('vault_note_id')
                ->constrained('tickets')->nullOnDelete();
        });

        Schema::table('routine_runs', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('agent_run_id')
                ->constrained('tickets')->nullOnDelete();
        });

        Schema::table('task_events', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('id')
                ->constrained('tickets')->nullOnDelete();
            $table->string('ticket_path')->nullable()->change();
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });

        Schema::table('routine_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vault_notes', 'status')) {
            Schema::table('vault_notes', function (Blueprint $table) {
                $table->dropIndex('vault_notes_status_index');
                $table->dropIndex('vault_notes_priority_index');
                $table->dropIndex('vault_notes_type_index');
                $table->dropIndex('vault_notes_assigned_to_index');
                $table->dropIndex('vault_notes_due_date_index');
                $table->dropColumn(['status', 'priority', 'type', 'assigned_to', 'tags', 'due_date']);
            });
        }

        if (Schema::hasColumn('agent_runs', 'vault_note_id')) {
            Schema::table('agent_runs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('vault_note_id');
                $table->dropColumn('ticket_path');
            });
        }

        if (Schema::hasColumn('routine_runs', 'ticket_path')) {
            Schema::table('routine_runs', function (Blueprint $table) {
                $table->dropColumn('ticket_path');
            });
        }

        if (Schema::hasColumn('task_events', 'ticket_path')) {
            Schema::table('task_events', function (Blueprint $table) {
                $table->dropIndex('task_events_ticket_path_event_type_index');
                $table->dropColumn('ticket_path');
            });
        }

        if (Schema::hasColumn('psych_interventions', 'ticket_path')) {
            Schema::table('psych_interventions', function (Blueprint $table) {
                $table->dropIndex('psych_interventions_ticket_path_index');
                $table->dropColumn('ticket_path');
            });
        }

        if (Schema::hasColumn('daily_checkins', 'vault_note_path')) {
            Schema::table('daily_checkins', function (Blueprint $table) {
                $table->dropColumn('vault_note_path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('vault_notes', function (Blueprint $table) {
            $table->string('status')->nullable()->index();
            $table->string('priority')->nullable()->index();
            $table->string('type')->nullable()->index();
            $table->string('assigned_to')->nullable()->index();
            $table->json('tags')->nullable();
            $table->date('due_date')->nullable()->index();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('vault_note_id')->nullable()->constrained('vault_notes')->nullOnDelete();
            $table->string('ticket_path')->nullable();
        });

        Schema::table('routine_runs', function (Blueprint $table) {
            $table->string('ticket_path')->nullable();
        });

        Schema::table('task_events', function (Blueprint $table) {
            $table->string('ticket_path')->nullable();
            $table->index(['ticket_path', 'event_type']);
        });

        Schema::table('psych_interventions', function (Blueprint $table) {
            $table->string('ticket_path')->nullable()->index();
        });

        Schema::table('daily_checkins', function (Blueprint $table) {
            $table->string('vault_note_path')->nullable();
        });
    }
};

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
        Schema::create('psych_interventions', function (Blueprint $table) {
            $table->id();
            $table->string('framework'); // chimp, zrm
            $table->string('intervention_type'); // reframe, somatic_checkin, resource_activation, motto_goal, avoidance_nudge, celebration
            $table->string('trigger'); // status_change, task_start, dashboard_load, wip_exceeded, task_complete
            $table->string('ticket_path')->nullable();
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->boolean('was_helpful')->nullable();
            $table->boolean('dismissed')->default(false);
            $table->timestamps();

            $table->index('framework');
            $table->index('ticket_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('psych_interventions');
    }
};

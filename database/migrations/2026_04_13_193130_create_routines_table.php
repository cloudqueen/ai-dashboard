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
        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('cron_expression'); // e.g. "0 5 * * *"
            $table->string('skill'); // vault or db skill name
            $table->text('prompt_template'); // task description for the agent
            $table->json('context_links')->nullable(); // vault note paths for extra context
            $table->string('priority')->default('medium');
            $table->string('model')->nullable(); // model override
            $table->string('output_folder')->nullable(); // vault folder for output
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};

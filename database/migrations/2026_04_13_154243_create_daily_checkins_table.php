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
        Schema::create('daily_checkins', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('mood')->nullable();
            $table->integer('energy')->nullable(); // 1-5
            $table->json('messages')->default('[]');
            $table->json('plan')->nullable();
            $table->string('motto_goal')->nullable();
            $table->text('summary')->nullable();
            $table->string('vault_note_path')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_checkins');
    }
};

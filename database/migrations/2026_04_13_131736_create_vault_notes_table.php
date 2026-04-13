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
        Schema::create('vault_notes', function (Blueprint $table) {
            $table->id();
            $table->string('relative_path')->unique();
            $table->string('title');
            $table->string('vault_folder')->index();
            $table->json('frontmatter')->nullable();
            $table->text('body_preview')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('priority')->nullable()->index();
            $table->string('type')->nullable()->index();
            $table->string('assigned_to')->nullable()->index();
            $table->json('tags')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->string('content_hash', 64);
            $table->timestamp('vault_modified_at');
            $table->timestamps();

            $table->index(['status', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vault_notes');
    }
};

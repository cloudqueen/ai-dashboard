<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_memories', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index(); // session | insight | pattern | consolidation
            $table->date('session_date')->nullable()->index();
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $dims = (int) config('dashboard.embeddings.dims', 512);
        DB::statement("ALTER TABLE coach_memories ADD COLUMN embedding vector({$dims})");
        DB::statement('CREATE INDEX coach_memories_embedding_cosine_idx ON coach_memories USING ivfflat (embedding vector_cosine_ops) WITH (lists = 10)');
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_memories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS coach_memories_embedding_cosine_idx');
        DB::statement('CREATE INDEX coach_memories_embedding_hnsw_idx ON coach_memories USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS coach_memories_embedding_hnsw_idx');
        DB::statement('CREATE INDEX coach_memories_embedding_cosine_idx ON coach_memories USING ivfflat (embedding vector_cosine_ops) WITH (lists = 10)');
    }
};

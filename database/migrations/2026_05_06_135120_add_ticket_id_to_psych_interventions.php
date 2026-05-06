<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psych_interventions', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('trigger')
                ->constrained('tickets')->nullOnDelete();
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('psych_interventions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });
    }
};

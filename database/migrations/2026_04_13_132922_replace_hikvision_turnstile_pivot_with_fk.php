<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hikvision_terminals', function (Blueprint $table) {
            $table->foreignId('access_point_id')->nullable()->constrained('access_points')->nullOnDelete();
        });

        // Migrate existing pivot data: pick the first linked access point per terminal
        DB::statement('
            UPDATE hikvision_terminals
            SET access_point_id = (
                SELECT access_point_id
                FROM hikvision_terminal_access_point
                WHERE hikvision_terminal_id = hikvision_terminals.id
                LIMIT 1
            )
        ');

        Schema::dropIfExists('hikvision_terminal_access_point');
    }

    public function down(): void
    {
        Schema::create('hikvision_terminal_access_point', function (Blueprint $table) {
            $table->foreignId('hikvision_terminal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_point_id')->constrained('access_points')->cascadeOnDelete();
            $table->unique(['hikvision_terminal_id', 'access_point_id']);
        });

        Schema::table('hikvision_terminals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_point_id');
        });
    }
};

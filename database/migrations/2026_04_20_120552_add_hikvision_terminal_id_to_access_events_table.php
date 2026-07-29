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
        Schema::table('access_events', function (Blueprint $table) {
            $table->foreignId('hikvision_terminal_id')
                ->nullable()
                ->after('access_point_id')
                ->constrained('hikvision_terminals')
                ->nullOnDelete();

            $table->index('hikvision_terminal_id');
        });
    }

    public function down(): void
    {
        Schema::table('access_events', function (Blueprint $table) {
            $table->dropForeign(['hikvision_terminal_id']);
            $table->dropIndex(['hikvision_terminal_id']);
            $table->dropColumn('hikvision_terminal_id');
        });
    }
};

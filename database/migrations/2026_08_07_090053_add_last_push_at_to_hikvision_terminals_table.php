<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a terminal stops pushing events, nothing local changes — the 30-minute polling
     * fallback keeps events arriving, so the outage is invisible until someone notices
     * passes showing up late. Stamping every webhook hit, heartbeats included, is what
     * makes "this terminal has not pushed in an hour" answerable at all.
     */
    public function up(): void
    {
        Schema::table('hikvision_terminals', function (Blueprint $table) {
            $table->timestamp('last_push_at')->nullable()->after('sync_stats');
        });
    }

    public function down(): void
    {
        Schema::table('hikvision_terminals', function (Blueprint $table) {
            $table->dropColumn('last_push_at');
        });
    }
};

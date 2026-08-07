<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `updated_at` on this row only moves when the cursor advances, which happens only when
     * RusGuard actually logged something — so a quiet hour and a poller that died look
     * identical. `polled_at` records that we looked, whether or not there was anything there.
     */
    public function up(): void
    {
        Schema::table('rusguard_audit_cursor', function (Blueprint $table) {
            $table->timestamp('polled_at')->nullable()->after('last_audit_id');
        });
    }

    public function down(): void
    {
        Schema::table('rusguard_audit_cursor', function (Blueprint $table) {
            $table->dropColumn('polled_at');
        });
    }
};

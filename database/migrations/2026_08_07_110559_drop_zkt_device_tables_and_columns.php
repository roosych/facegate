<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ZKT/ZKBio branch is gone. It had been dormant for a while and carried no data at
     * all — no access point referenced a device, no sync log or access event carried a
     * device_id, no employee had ever been given a zkbio_id — so this drops structure only.
     *
     * There is no down(): recreating empty columns would restore the shape without the code
     * that gave it meaning. If ZKT comes back, the branch comes back from git history along
     * with its own migrations.
     */
    public function up(): void
    {
        Schema::table('access_points', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enter_device_id');
            $table->dropConstrainedForeignId('exit_device_id');
        });

        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });

        Schema::table('access_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('zkbio_id');
        });

        Schema::dropIfExists('devices');
    }
};

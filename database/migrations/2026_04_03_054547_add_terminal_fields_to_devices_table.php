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
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('alias')->nullable()->after('sn');
            $table->string('terminal_name')->nullable()->after('alias');
            $table->string('area_name')->nullable()->after('location');
            $table->timestamp('last_activity')->nullable()->after('area_name');
            $table->unsignedInteger('user_count')->default(0)->after('last_activity');
            $table->unsignedInteger('face_count')->default(0)->after('user_count');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['alias', 'terminal_name', 'area_name', 'last_activity', 'user_count', 'face_count']);
        });
    }
};

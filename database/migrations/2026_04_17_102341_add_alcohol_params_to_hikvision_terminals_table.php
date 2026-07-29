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
        Schema::table('hikvision_terminals', function (Blueprint $table) {
            $table->json('alcohol_params')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hikvision_terminals', function (Blueprint $table) {
            $table->dropColumn('alcohol_params');
        });
    }
};

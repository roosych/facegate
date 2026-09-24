<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `alcohol_cleaning_notified_at` guards against re-sending the "needs cleaning" email every
     * day the threshold stays crossed — it's cleared whenever `alcohol_last_cleaned_at` is
     * stamped, so exactly one notification goes out per cleaning cycle.
     */
    public function up(): void
    {
        Schema::table('hikvision_terminals', function (Blueprint $table) {
            $table->timestamp('alcohol_last_cleaned_at')->nullable()->after('last_push_at');
            $table->timestamp('alcohol_cleaning_notified_at')->nullable()->after('alcohol_last_cleaned_at');
        });
    }

    public function down(): void
    {
        Schema::table('hikvision_terminals', function (Blueprint $table) {
            $table->dropColumn(['alcohol_last_cleaned_at', 'alcohol_cleaning_notified_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Single-row table: tracks the last RusGuard AuditData._id we've reacted to, so
        // rusguard:poll-audit can resume from where it left off instead of rescanning.
        // null last_audit_id means "not yet bootstrapped" — the command initializes it to
        // the current latest id on first run rather than processing the entire audit history.
        Schema::create('rusguard_audit_cursor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('last_audit_id')->nullable();
            $table->timestamps();
        });

        DB::table('rusguard_audit_cursor')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rusguard_audit_cursor');
    }
};

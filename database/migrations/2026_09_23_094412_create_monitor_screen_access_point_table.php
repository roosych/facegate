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
        // Which turnstiles a given physical monitor screen shows, and in what order (left to
        // right) — a screen can list the same access point only once, enforced below rather
        // than in the app layer so a race between two admin tabs can't duplicate a row.
        Schema::create('monitor_screen_access_point', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_screen_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_point_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['monitor_screen_id', 'access_point_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_screen_access_point');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hikvision_terminal_access_point', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hikvision_terminal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_point_id')->constrained('access_points')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['hikvision_terminal_id', 'access_point_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hikvision_terminal_access_point');
    }
};

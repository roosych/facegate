<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_point_employee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_point_id')->constrained('access_points')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'access_point_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_point_employee');
    }
};

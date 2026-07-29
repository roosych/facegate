<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_points', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->uuid('rusguard_access_point_id');
            $table->string('rusguard_access_point_name');
            $table->string('device_type')->nullable();
            $table->foreignId('enter_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('exit_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_points');
    }
};

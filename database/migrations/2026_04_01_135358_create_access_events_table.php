<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('access_point_id')->nullable()->constrained('access_points')->nullOnDelete();
            $table->timestamp('event_time');
            $table->string('verify_type');
            $table->string('direction')->nullable();
            $table->string('card_no')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index('event_time');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};

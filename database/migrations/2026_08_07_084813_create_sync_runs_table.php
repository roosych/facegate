<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('triggered_by');
            $table->string('status');
            $table->foreignId('hikvision_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('access_point_id')->nullable()->constrained('access_points')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('stats')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['kind', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};

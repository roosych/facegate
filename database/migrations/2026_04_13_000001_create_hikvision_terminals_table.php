<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hikvision_terminals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip', 45);
            $table->unsignedSmallInteger('port')->default(80);
            $table->string('username')->default('admin');
            $table->text('password');
            $table->string('protocol', 5)->default('http');
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hikvision_terminals');
    }
};

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
        // Migrate existing card_no values into employee_keys
        DB::table('employees')
            ->whereNotNull('card_no')
            ->where('card_no', '!=', '')
            ->orderBy('id')
            ->each(function (object $employee): void {
                DB::table('employee_keys')->insertOrIgnore([
                    'employee_id' => $employee->id,
                    'type'        => 'card',
                    'value'       => $employee->card_no,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('card_no');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('card_no')->nullable();
        });

        // Restore first card per employee back to card_no
        DB::table('employee_keys')
            ->where('type', 'card')
            ->orderBy('id')
            ->each(function (object $key): void {
                DB::table('employees')
                    ->where('id', $key->employee_id)
                    ->whereNull('card_no')
                    ->update(['card_no' => $key->value]);
            });
    }
};

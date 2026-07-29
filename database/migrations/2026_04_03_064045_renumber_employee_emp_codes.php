<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('employees')->orderBy('id')->pluck('id');

        foreach ($ids as $index => $id) {
            DB::table('employees')->where('id', $id)->update([
                'emp_code' => $index + 1,
                'zkbio_id' => null,
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible — forward-fix migration
    }
};

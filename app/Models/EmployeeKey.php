<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeKey extends Model
{
    protected $fillable = ['employee_id', 'type', 'value'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

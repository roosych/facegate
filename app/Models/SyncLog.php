<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'hikvision_terminal_id', 'action', 'status', 'message', 'payload'])]
class SyncLog extends Model
{
    use HasFactory;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function hikvisionTerminal(): BelongsTo
    {
        return $this->belongsTo(HikvisionTerminal::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}

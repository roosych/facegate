<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'ip', 'sn', 'zkbio_terminal_id', 'location', 'is_active', 'alias', 'terminal_name', 'area_name', 'last_activity', 'user_count', 'face_count'])]
class Device extends Model
{
    use HasFactory;

    public function accessEvents(): HasMany
    {
        return $this->hasMany(AccessEvent::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_activity' => 'datetime',
        ];
    }
}

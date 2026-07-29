<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'rusguard_access_point_id', 'rusguard_access_point_name', 'device_type', 'enter_device_id', 'exit_device_id', 'is_active'])]
class Turnstile extends Model
{
    use HasFactory;

    protected $table = 'access_points';

    public function enterDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'enter_device_id');
    }

    public function exitDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'exit_device_id');
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'access_point_employee', 'access_point_id', 'employee_id')->withTimestamps();
    }

    public function hikvisionTerminal(): HasOne
    {
        return $this->hasOne(HikvisionTerminal::class, 'access_point_id');
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(AccessEvent::class, 'access_point_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}

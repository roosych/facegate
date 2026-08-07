<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['rusguard_uuid', 'emp_code', 'first_name', 'last_name', 'middle_name', 'photo_path', 'zkbio_id', 'is_active', 'last_synced_at', 'alcohol_skip_until'])]
class Employee extends Model
{
    use HasFactory;

    public function keys(): HasMany
    {
        return $this->hasMany(EmployeeKey::class);
    }

    /** First card value, or null — convenience accessor */
    public function getCardNoAttribute(): ?string
    {
        return $this->keys->where('type', 'card')->first()?->value;
    }

    public function accessPoints(): BelongsToMany
    {
        return $this->belongsToMany(AccessPoint::class, 'access_point_employee', 'employee_id', 'access_point_id')->withTimestamps();
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(AccessEvent::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->last_name, $this->first_name, $this->middle_name])));
    }

    /** Whether the employee is within the post-alcohol-test grace period (test not required right now). */
    public function isAlcoholSkipActive(): bool
    {
        return $this->alcohol_skip_until !== null && $this->alcohol_skip_until->isFuture();
    }

    /**
     * Hikvision terminals, among those linked via RusGuard-synced access point access, that have
     * alcohol detection enabled — the set that a "skip_alcohol" push needs to reach.
     *
     * @return Collection<int, HikvisionTerminal>
     */
    public function alcoholEnabledTerminals(): Collection
    {
        return $this->accessPoints()
            ->with('hikvisionTerminal')
            ->get()
            ->pluck('hikvisionTerminal')
            ->filter(fn (?HikvisionTerminal $terminal) => $terminal !== null
                && (bool) ($terminal->resolvedAlcoholParams()['enabled'] ?? false));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'alcohol_skip_until' => 'datetime',
        ];
    }
}

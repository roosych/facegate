<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
#[Fillable(['employee_id', 'device_id', 'hikvision_terminal_id', 'access_point_id', 'event_time', 'verify_type', 'direction', 'card_no', 'raw_data'])]
class AccessEvent extends Model
{
    use HasFactory;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function turnstile(): BelongsTo
    {
        return $this->belongsTo(Turnstile::class, 'access_point_id');
    }

    public function hikvisionTerminal(): BelongsTo
    {
        return $this->belongsTo(HikvisionTerminal::class);
    }

    public function scopeHasAlcoholTest(Builder $query): void
    {
        // The jsonb-only `?` (key exists) operator doesn't work on this column — raw_data is
        // a plain `json` column — so check for a non-null value at the path instead. This form
        // is portable across Postgres' json/jsonb and SQLite's json_extract emulation.
        $query->whereNotNull('raw_data->alcoholDetectionInfo');
    }

    public function scopeAlcoholPassed(Builder $query): void
    {
        $query->where('raw_data->alcoholDetectionInfo->result', 'normal');
    }

    public function scopeAlcoholFailed(Builder $query): void
    {
        $query->whereNotNull('raw_data->alcoholDetectionInfo')
            ->where('raw_data->alcoholDetectionInfo->result', '!=', 'normal');
    }

    public function scopeAlcoholAboveZero(Builder $query): void
    {
        $query->whereRaw(
            "(raw_data->'alcoholDetectionInfo'->'concentrationInfo'->>'concentrationValue')::numeric > 0"
        );
    }

    /** True when the raw_data contains an alcohol test result. */
    public function hasAlcoholResult(): bool
    {
        return isset($this->raw_data['alcoholDetectionInfo']);
    }

    /** Whether the alcohol test was passed (result === 'normal'). */
    public function alcoholPassed(): ?bool
    {
        if (! $this->hasAlcoholResult()) {
            return null;
        }

        return ($this->raw_data['alcoholDetectionInfo']['result'] ?? '') === 'normal';
    }

    public function alcoholConcentration(): ?float
    {
        $value = $this->raw_data['alcoholDetectionInfo']['concentrationInfo']['concentrationValue'] ?? null;

        return $value !== null ? (float) $value : null;
    }

    /** Alcohol concentration converted to promille (‰). Formula: 1 mg/100ml = 0.01‰ */
    public function alcoholPromille(): ?float
    {
        $value = $this->alcoholConcentration();

        return $value !== null ? round($value * 0.01, 2) : null;
    }

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'raw_data' => 'array',
        ];
    }
}

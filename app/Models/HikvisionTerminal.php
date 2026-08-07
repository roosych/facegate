<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'ip', 'port', 'username', 'password', 'protocol', 'location', 'is_active', 'access_point_id', 'alcohol_params', 'sync_stats', 'last_push_at'])]
class HikvisionTerminal extends Model
{
    use HasFactory;

    public function turnstile(): BelongsTo
    {
        return $this->belongsTo(Turnstile::class, 'access_point_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(AccessEvent::class);
    }

    /**
     * Default alcohol detection parameters used when none are stored or fetched.
     *
     * @return array<string, mixed>
     */
    public function defaultAlcoholParams(): array
    {
        return [
            'enabled' => false,
            'drinkingThreshold' => 20,
            'drunkennessThreshold' => 80,
            'timeout' => 20,
            'weekPlan' => [
                'monday' => ['enabled' => true,  'periods' => [['beginTime' => '08:00', 'endTime' => '18:00']]],
                'tuesday' => ['enabled' => true,  'periods' => [['beginTime' => '08:00', 'endTime' => '18:00']]],
                'wednesday' => ['enabled' => true,  'periods' => [['beginTime' => '08:00', 'endTime' => '18:00']]],
                'thursday' => ['enabled' => true,  'periods' => [['beginTime' => '08:00', 'endTime' => '18:00']]],
                'friday' => ['enabled' => true,  'periods' => [['beginTime' => '08:00', 'endTime' => '18:00']]],
                'saturday' => ['enabled' => false, 'periods' => [['beginTime' => '09:00', 'endTime' => '14:00']]],
                'sunday' => ['enabled' => false, 'periods' => [['beginTime' => '09:00', 'endTime' => '14:00']]],
            ],
        ];
    }

    /**
     * Merge stored params over defaults so new fields always have a value.
     * Each weekPlan day is replaced wholesale (not deep-merged) since its 'periods'
     * list would otherwise be corrupted by index-based array_replace_recursive.
     *
     * @return array<string, mixed>
     */
    public function resolvedAlcoholParams(): array
    {
        $defaults = $this->defaultAlcoholParams();
        $stored = $this->alcohol_params ?? [];

        $resolved = array_replace($defaults, $stored);
        $resolved['weekPlan'] = array_replace($defaults['weekPlan'], $stored['weekPlan'] ?? []);

        return $resolved;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'encrypted',
            'port' => 'integer',
            'alcohol_params' => 'array',
            'sync_stats' => 'array',
            'last_push_at' => 'datetime',
        ];
    }
}

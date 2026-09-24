<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'ip', 'port', 'username', 'password', 'protocol', 'location', 'direction', 'is_active', 'access_point_id', 'alcohol_params', 'sync_stats', 'last_push_at', 'alcohol_last_cleaned_at', 'alcohol_cleaning_notified_at'])]
class HikvisionTerminal extends Model
{
    use HasFactory;

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class, 'access_point_id');
    }

    /** Cache key holding the latest push event for this terminal, for the live monitor screen. */
    public function monitorCacheKey(): string
    {
        return 'monitor_terminal_event_'.$this->id;
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

    /** Alcohol tests run on this terminal since it was last cleaned (or ever, if never cleaned). */
    public function alcoholTestCountSinceCleaning(): int
    {
        return $this->accessEvents()
            ->hasAlcoholTest()
            ->when(
                $this->alcohol_last_cleaned_at,
                fn ($query) => $query->where('event_time', '>', $this->alcohol_last_cleaned_at)
            )
            ->count();
    }

    public function needsAlcoholCleaning(): bool
    {
        return $this->alcoholTestCountSinceCleaning() >= config('alcohol.cleaning_threshold');
    }

    /** Stamps the terminal as freshly cleaned and re-arms the threshold email for the next cycle. */
    public function markAlcoholCleaned(): void
    {
        $this->update([
            'alcohol_last_cleaned_at' => now(),
            'alcohol_cleaning_notified_at' => null,
        ]);
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
            'alcohol_last_cleaned_at' => 'datetime',
            'alcohol_cleaning_notified_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named, persistent grouping of turnstiles for one physical monitor — e.g. "Вход в офис"
 * showing two turnstiles side by side. Exists so the browser on a monitor screen stationed at a
 * turnstile location (a guard post, reception desk, etc.) can be pointed at one stable signed URL
 * (see MonitorController::showScreen()) that keeps working after the admin adds, removes or
 * reorders the turnstiles it shows, instead of baking a fixed list of access point ids into the
 * URL itself.
 */
#[Fillable(['name'])]
class MonitorScreen extends Model
{
    use HasFactory;

    public function accessPoints(): BelongsToMany
    {
        return $this->belongsToMany(AccessPoint::class, 'monitor_screen_access_point')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * Replace this screen's access points with the given ones, in the given (left-to-right)
     * order.
     *
     * @param  array<int, int>  $accessPointIds
     */
    public function setAccessPoints(array $accessPointIds): void
    {
        $ordered = collect(array_values(array_unique($accessPointIds)))
            ->mapWithKeys(fn (int $id, int $position) => [$id => ['position' => $position]])
            ->all();

        $this->accessPoints()->sync($ordered);
    }
}

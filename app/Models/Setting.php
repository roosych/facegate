<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function alcoholSkipGraceMinutes(): int
    {
        return (int) static::get('alcohol_skip_grace_minutes', (string) config('alcohol.skip_grace_minutes_default'));
    }

    /** Concentration (mg/100ml) at or above which a failed test triggers an email notification. */
    public static function alcoholNotificationThreshold(): float
    {
        return (float) static::get('alcohol_notification_threshold', (string) config('alcohol.notification_threshold_default'));
    }

    /** @return array<int, string> */
    public static function alcoholNotificationEmails(): array
    {
        $raw = static::get('alcohol_notification_emails', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}

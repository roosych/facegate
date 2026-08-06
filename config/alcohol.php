<?php

return [
    // Default grace period (minutes) before an employee must alcohol-test again after passing —
    // only used to seed the `settings` table on first read. RusGuard's own AlcoGroup.PeriodAlcoTesting
    // field is unrelated (confirmed to be a testing-frequency compliance cycle in days, not this).
    // Change the live value via the /alcohol page UI, not this file.
    'skip_grace_minutes_default' => (int) env('ALCOHOL_SKIP_GRACE_MINUTES', 180),

    // Default concentration (mg/100ml) at or above which a failed test emails the configured
    // recipients — matches the device's own "drinking" threshold. Change the live value via
    // the /alcohol page UI, not this file.
    'notification_threshold_default' => (float) env('ALCOHOL_NOTIFICATION_THRESHOLD', 20),
];

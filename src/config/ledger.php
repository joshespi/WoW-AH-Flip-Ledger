<?php

return [
    // Selectable on the account. Only US for now; the schema is already region-aware.
    'regions' => [
        'us' => 'US',
    ],

    // Redirect plain HTTP to APP_URL (which must be https). Turn on in production once
    // TRUSTED_PROXIES is right, or every request looks like HTTP and this loops.
    'force_https' => (bool) env('LEDGER_FORCE_HTTPS', false),

    // Written by the backup service after each good dump; health:check complains if it gets old.
    // Unset where there is no backup service (local).
    'backup' => [
        'status_file' => env('LEDGER_BACKUP_STATUS_FILE'),
        'max_age_hours' => (int) env('LEDGER_BACKUP_MAX_AGE_HOURS', 36),
    ],

    'health' => [
        // A problem is mailed to the admins when it starts, then again this often while it lasts.
        'reminder_hours' => (int) env('LEDGER_HEALTH_REMINDER_HOURS', 12),
    ],

    // Shown on About, Privacy and Terms when set.
    'contact_email' => env('LEDGER_CONTACT_EMAIL'),

    // Uploaders older than this are told to update (HTTP 426). Raise it when the ingest
    // contract changes in a way old clients can't satisfy.
    'min_client_version' => env('LEDGER_MIN_CLIENT_VERSION', '0.0.0'),

    'prices' => [
        // Hourly snapshots of every commodity are kept this long, then only the daily rollup remains.
        'snapshot_days' => (int) env('LEDGER_SNAPSHOT_DAYS', 30),
        'daily_days' => 400,
        'fetch_run_days' => 30,

        // Derived metrics (recent, typical, 60d hist, sold/day) are shown only once an item has
        // this many days of history; below it the UI says "collecting data".
        'min_days' => 14,

        // Fetches run hourly; the header flags prices stale after this long without a good one.
        'stale_hours' => 3,
        'sold_window_days' => 7,

        // Item name lookups are paced well under Blizzard's 100 requests/second limit.
        'names_per_run' => 300,
        'names_pause_ms' => 100,
    ],

    'positions' => [
        // Warn when one item is more than this share of stock at cost.
        'concentration_pct' => 40,

        // Floor vs typical price: at or under `below` is cheap, at or over `above` is rich
        // (the legacy market command's 0.75 / 1.15). Only judged once stats have enough history.
        'below_ratio' => 0.75,
        'above_ratio' => 1.15,

        'dashboard_rows' => 12,
        'recent_activity' => 20,
    ],

    'market' => [
        // Falling knife: floor at or under this share of typical while still dropping.
        // Placeholder from the legacy tool; recalibrate with Phase 4 data.
        'knife_ratio' => 0.8,

        'scan_default_top' => 50,
        'scan_max_top' => 200,
    ],

    'alerts' => [
        // After an alert for an item and rule is sent, it cannot fire again for this long, even if
        // the price drops out of range and comes back.
        'cooldown_hours' => (int) env('LEDGER_ALERT_COOLDOWN_HOURS', 24),

        // One email per user per run; a longer list is carried over to the next run.
        'max_items_per_email' => 50,
    ],

    'addon' => [
        // The uploader polls every few minutes; this leaves room for retries and restarts.
        'per_token_per_hour' => (int) env('LEDGER_ADDON_FEED_PER_HOUR', 60),

        // Open positions in the feed; the rest are dropped and `truncated` is set.
        'max_positions' => 300,
    ],

    'ingest' => [
        // Keep in step with nginx client_max_body_size for the ingest and upload paths and with
        // PHP post_max_size / upload_max_filesize (docker/php/zz-app.ini).
        'max_request_bytes' => 50 * 1024 * 1024,

        // Uploader pushes after each /reload, so this is generous for normal use.
        'per_token_per_hour' => (int) env('LEDGER_INGEST_PER_HOUR', 30),
        'per_ip_per_minute' => (int) env('LEDGER_INGEST_PER_IP_MINUTE', 60),
        'manual_per_user_per_hour' => (int) env('LEDGER_MANUAL_UPLOAD_PER_HOUR', 20),
        'trades_import_per_user_per_hour' => (int) env('LEDGER_TRADES_IMPORT_PER_HOUR', 20),
    ],
];

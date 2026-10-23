<?php

return [
    'version' => '0.1.1',
    'down_after' => (int) env('DBA_DOWN_AFTER', 2),      // failed polls in a row before an instance is "down"
    'http_timeout' => (int) env('DBA_HTTP_TIMEOUT', 20),
    'alert_emails' => env('DBA_ALERT_EMAILS'),             // extra comma-separated recipients (admins always get alerts)
    'latest_version' => env('DBA_LATEST_VERSION'),
    'github' => ['repo' => env('DBA_GITHUB_REPO'), 'token' => env('DBA_GITHUB_TOKEN')],
    'owner' => ['email' => env('DBA_OWNER_EMAIL'), 'name' => env('DBA_OWNER_NAME', 'Owner')],
    'window' => 300,
];

<?php

return [

    /*
    | Telegram numeric ID of the owner. The owner appoints admins and sets the scoring rules.
    | Anyone can find their ID with the /id command.
    */
    'owner_id' => (int) env('TELEGRAM_OWNER_ID', 0),

    /*
    | First day of season 1. Every season lasts 3 months from this date.
    */
    'season_start' => env('LOOP_SEASON_START', '2026-09-23'),

    /*
    | Timezone used to decide where a season (and its days) starts and ends.
    */
    'timezone' => env('LOOP_TIMEZONE', 'Asia/Tehran'),

    /*
    | Pause between messages of one broadcast, to stay under Telegram's limit of ~30 messages per second.
    */
    'broadcast_delay_ms' => (int) env('LOOP_BROADCAST_DELAY_MS', 50),

];

<?php

/*
|--------------------------------------------------------------------------
| CaféVerse business rules
|--------------------------------------------------------------------------
| Values that control Café Yatra and uploads. Each one can be changed in
| .env without touching code (e.g. set CAFEVERSE_MIN_VISIT_MINUTES=0 for a
| live demo where you check in and out straight away).
*/

return [

    // How close (in metres) a customer must be to check in.
    'check_in_radius_metres' => (int) env('CAFEVERSE_CHECK_IN_RADIUS', 100),

    // Minimum time between check-in and QR checkout for a visit to count.
    'min_visit_minutes' => (int) env('CAFEVERSE_MIN_VISIT_MINUTES', 5),

    // An unfinished visit older than this is treated as abandoned.
    'max_visit_hours' => (int) env('CAFEVERSE_MAX_VISIT_HOURS', 12),

    // Only one completed visit per café per day counts towards badges/rewards.
    'one_visit_per_cafe_per_day' => (bool) env('CAFEVERSE_ONE_VISIT_PER_DAY', true),

    // Time zone used for "per day" rules and dates shown to users.
    'timezone' => env('CAFEVERSE_TIMEZONE', 'Asia/Kathmandu'),

    // Upload limits in kilobytes.
    'max_image_kb' => 5120,
    'max_video_kb' => 51200,
];

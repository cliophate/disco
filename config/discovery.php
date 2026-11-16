<?php

return [
    'batch_size' => min(20, max(1, (int) env('DISCOVERY_BATCH_SIZE', 10))),
    'seed_limit' => min(50, max(1, (int) env('DISCOVERY_SEED_LIMIT', 30))),
    'radio_artist_limit' => min(100, max(1, (int) env('DISCOVERY_RADIO_ARTIST_LIMIT', 30))),
    'lifetime_listen_threshold' => max(0, (int) env('DISCOVERY_LIFETIME_LISTEN_THRESHOLD', 5)),
    'repeat_weeks' => max(1, (int) env('DISCOVERY_REPEAT_WEEKS', 12)),
    'seed_cap' => max(1, (int) env('DISCOVERY_SEED_CAP', 2)),
    'popularity_penalty' => max(0, (float) env('DISCOVERY_POPULARITY_PENALTY', 0.5)),
    'popular_listener_threshold' => max(1, (int) env('DISCOVERY_POPULAR_LISTENER_THRESHOLD', 10000)),
    'fresh_days' => min(90, max(1, (int) env('DISCOVERY_FRESH_DAYS', 30))),
    'album_lookup_limit' => min(200, max(10, (int) env('DISCOVERY_ALBUM_LOOKUP_LIMIT', 50))),
    'excluded_secondary_types' => [
        'compilation', 'live', 'remix', 'dj-mix', 'demo', 'interview',
        'spokenword', 'audiobook', 'audio drama', 'mixtape/street',
    ],
];

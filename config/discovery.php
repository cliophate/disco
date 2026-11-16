<?php

return [
    'batch_size' => min(20, max(1, (int) env('DISCOVERY_BATCH_SIZE', 10))),
    'seed_limit' => min(50, max(1, (int) env('DISCOVERY_SEED_LIMIT', 30))),
    'radio_artist_limit' => min(100, max(1, (int) env('DISCOVERY_RADIO_ARTIST_LIMIT', 30))),
    'lifetime_listen_threshold' => max(0, (int) env('DISCOVERY_LIFETIME_LISTEN_THRESHOLD', 5)),
    'repeat_weeks' => max(1, (int) env('DISCOVERY_REPEAT_WEEKS', 12)),
    'seed_cap' => max(1, (int) env('DISCOVERY_SEED_CAP', 2)),
    'soundtrack_cap' => max(0, (int) env('DISCOVERY_SOUNDTRACK_CAP', 2)),
    'soundtrack_seed_threshold' => min(1, max(0, (float) env('DISCOVERY_SOUNDTRACK_SEED_THRESHOLD', 0.5))),
    'max_listener_count' => max(0, (int) env('DISCOVERY_MAX_LISTENER_COUNT', 25000)),
    'popular_listener_threshold' => max(1, (int) env('DISCOVERY_POPULAR_LISTENER_THRESHOLD', 5000)),
    'fresh_days' => min(90, max(1, (int) env('DISCOVERY_FRESH_DAYS', 30))),
    'album_lookup_limit' => min(200, max(10, (int) env('DISCOVERY_ALBUM_LOOKUP_LIMIT', 50))),
    'more_musicbrainz_call_limit' => max(1, (int) env('DISCOVERY_MORE_MUSICBRAINZ_CALL_LIMIT', 150)),
    'excluded_secondary_types' => [
        'compilation', 'live', 'remix', 'dj-mix', 'demo', 'interview',
        'spokenword', 'audiobook', 'audio drama', 'mixtape/street',
    ],
];

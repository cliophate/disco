<?php

return [
    'presentation_cooldown_days' => (int) env('DISCOVERY_PRESENTATION_COOLDOWN_DAYS', 30),
    'artist_cap_per_module' => (int) env('DISCOVERY_ARTIST_CAP_PER_MODULE', 2),
    'editorial' => [
        'pitchfork' => [
            'enabled' => filter_var(env('PITCHFORK_RSS_ENABLED', false), FILTER_VALIDATE_BOOL),
            'feeds' => [
                'reviews' => 'https://pitchfork.com/feed/feed-album-reviews/rss',
                'news' => 'https://pitchfork.com/feed/feed-news/rss',
            ],
            'connect_timeout_seconds' => min(10, max(1, (int) env('PITCHFORK_RSS_CONNECT_TIMEOUT_SECONDS', 5))),
            'timeout_seconds' => min(30, max(5, (int) env('PITCHFORK_RSS_TIMEOUT_SECONDS', 15))),
            'maximum_bytes' => min(2_000_000, max(100_000, (int) env('PITCHFORK_RSS_MAXIMUM_BYTES', 1_000_000))),
            'maximum_items_per_feed' => min(100, max(1, (int) env('PITCHFORK_RSS_MAXIMUM_ITEMS', 50))),
            'maximum_cards' => min(12, max(1, (int) env('PITCHFORK_RSS_MAXIMUM_CARDS', 6))),
            'expiry_hours' => min(168, max(24, (int) env('PITCHFORK_RSS_EXPIRY_HOURS', 72))),
            'retention_days' => min(90, max(7, (int) env('PITCHFORK_RSS_RETENTION_DAYS', 30))),
        ],
    ],
    'upcoming' => [
        'stale_after_hours' => max(24, (int) env('UPCOMING_STALE_AFTER_HOURS', 36)),
        'notification_max_items' => max(1, (int) env('UPCOMING_NOTIFICATION_MAX_ITEMS', 5000)),
        'notification_missing_threshold' => max(2, (int) env('UPCOMING_NOTIFICATION_MISSING_THRESHOLD', 2)),
        'excluded_secondary_types' => [
            'audio drama', 'audiobook', 'compilation', 'demo', 'dj-mix', 'interview',
            'live', 'mixtape/street', 'remix', 'spokenword',
        ],
    ],
    'discography' => [
        'page_size' => min(100, max(25, (int) env('DISCOGRAPHY_PAGE_SIZE', 100))),
        'max_pages' => min(10, max(1, (int) env('DISCOGRAPHY_MAX_PAGES', 3))),
        'official_release_max_pages' => min(5, max(1, (int) env('DISCOGRAPHY_OFFICIAL_RELEASE_MAX_PAGES', 2))),
        'stale_after_days' => min(180, max(1, (int) env('DISCOGRAPHY_STALE_AFTER_DAYS', 90))),
        'excluded_secondary_types' => [
            'audio drama', 'audiobook', 'compilation', 'demo', 'dj-mix', 'interview',
            'live', 'mixtape/street', 'remix', 'soundtrack', 'spokenword',
        ],
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'plex' => [
        'url' => env('PLEX_URL'),
        'token' => env('PLEX_TOKEN'),
        'library' => env('PLEX_LIBRARY_NAME', 'Music'),
        'expected_machine_identifier' => env('PLEX_EXPECTED_MACHINE_IDENTIFIER'),
        'expected_library_uuid' => env('PLEX_EXPECTED_LIBRARY_UUID'),
        'allow_insecure_http' => env('PLEX_ALLOW_INSECURE_HTTP', false),
        'timeout' => (int) env('PLEX_TIMEOUT_SECONDS', 30),
        'artwork_auto_ingest' => env('PLEX_ARTWORK_AUTO_INGEST', true),
        'playback_context_ttl_seconds' => min(300, max(60, (int) env('PLEX_PLAYBACK_CONTEXT_TTL_SECONDS', 120))),
        'playback_recent_days' => min(365, max(1, (int) env('PLEX_PLAYBACK_RECENT_DAYS', 90))),
        'max_concurrent_streams' => min(2, max(1, (int) env('PLEX_MAX_CONCURRENT_STREAMS', 2))),
    ],

    'musicbrainz' => [
        'url' => env('MUSICBRAINZ_URL', 'https://musicbrainz.org/ws/2'),
        'user_agent' => env('MUSICBRAINZ_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
        'timeout' => (int) env('MUSICBRAINZ_TIMEOUT_SECONDS', 30),
        'rate_interval_ms' => (int) env('MUSICBRAINZ_RATE_INTERVAL_MS', 1100),
    ],

    'discogs' => [
        'url' => env('DISCOGS_URL', 'https://api.discogs.com'),
        'token' => env('DISCOGS_TOKEN'),
        'timeout' => (int) env('DISCOGS_TIMEOUT_SECONDS', 20),
        'rate_interval_ms' => (int) env('DISCOGS_RATE_INTERVAL_MS', 1100),
        'user_agent' => env('DISCOGS_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
    ],

    'cover_art_archive' => [
        'url' => env('COVER_ART_ARCHIVE_URL', 'https://coverartarchive.org'),
        'user_agent' => env('COVER_ART_ARCHIVE_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
        'timeout' => (int) env('COVER_ART_ARCHIVE_TIMEOUT_SECONDS', 30),
        'release_attempt_limit' => min(8, max(2, (int) env('COVER_ART_ARCHIVE_RELEASE_ATTEMPT_LIMIT', 5))),
        'missing_ttl_days' => min(365, max(1, (int) env('COVER_ART_ARCHIVE_MISSING_TTL_DAYS', 30))),
        'retry_ttl_hours' => min(168, max(1, (int) env('COVER_ART_ARCHIVE_RETRY_TTL_HOURS', 24))),
    ],

    'theaudiodb' => [
        'url' => env('THEAUDIODB_URL', 'https://www.theaudiodb.com/api/v1/json'),
        'api_key' => env('THEAUDIODB_API_KEY', '123'),
        'timeout' => (int) env('THEAUDIODB_TIMEOUT_SECONDS', 30),
        'rate_interval_ms' => (int) env('THEAUDIODB_RATE_INTERVAL_MS', 2100),
        'user_agent' => env('THEAUDIODB_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
    ],

    'wikimedia' => [
        'language' => env('WIKIMEDIA_LANGUAGE', 'en'),
        'timeout' => (int) env('WIKIMEDIA_TIMEOUT_SECONDS', 30),
        'user_agent' => env('WIKIMEDIA_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
    ],

    'listenbrainz' => [
        'url' => env('LISTENBRAINZ_URL', 'https://api.listenbrainz.org'),
        'username' => env('LISTENBRAINZ_USERNAME'),
        'token' => env('LISTENBRAINZ_TOKEN'),
        'enabled' => filled(env('LISTENBRAINZ_USERNAME')) && filled(env('LISTENBRAINZ_TOKEN')),
        'timeout' => (int) env('LISTENBRAINZ_TIMEOUT_SECONDS', 30),
        'page_size' => min(1000, max(1, (int) env('LISTENBRAINZ_PAGE_SIZE', 1000))),
        'overlap_seconds' => max(0, (int) env('LISTENBRAINZ_OVERLAP_SECONDS', 3600)),
        'rate_interval_ms' => max(0, (int) env('LISTENBRAINZ_RATE_INTERVAL_MS', 250)),
        'recommendation_count' => min(1000, max(1, (int) env('LISTENBRAINZ_RECOMMENDATION_COUNT', 500))),
        'recommendation_limit' => min(50, max(1, (int) env('LISTENBRAINZ_RECOMMENDATION_LIMIT', 50))),
        'recommendation_lookup_budget' => min(100, max(1, (int) env('LISTENBRAINZ_RECOMMENDATION_LOOKUP_BUDGET', 100))),
        'user_agent' => env('LISTENBRAINZ_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
    ],

    'qobuz' => [
        'storefront' => env('QOBUZ_STOREFRONT', 'us-en'),
    ],

    'gotify' => [
        'url' => env('GOTIFY_URL'),
        'token' => env('GOTIFY_TOKEN'),
        'timeout' => min(30, max(1, (int) env('GOTIFY_TIMEOUT_SECONDS', 10))),
        'priority' => min(10, max(-2, (int) env('GOTIFY_PRIORITY', 5))),
    ],

];

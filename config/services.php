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

    'plex' => [
        'url' => env('PLEX_URL'),
        'token' => env('PLEX_TOKEN'),
        'library' => env('PLEX_LIBRARY_NAME', 'Music'),
        'expected_machine_identifier' => env('PLEX_EXPECTED_MACHINE_IDENTIFIER'),
        'expected_library_uuid' => env('PLEX_EXPECTED_LIBRARY_UUID'),
        'allow_insecure_http' => env('PLEX_ALLOW_INSECURE_HTTP', false),
        'timeout' => (int) env('PLEX_TIMEOUT_SECONDS', 30),
        'artwork_auto_ingest' => env('PLEX_ARTWORK_AUTO_INGEST', true),
    ],

    'musicbrainz' => [
        'url' => env('MUSICBRAINZ_URL', 'https://musicbrainz.org/ws/2'),
        'user_agent' => env('MUSICBRAINZ_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
        'timeout' => (int) env('MUSICBRAINZ_TIMEOUT_SECONDS', 30),
        'rate_interval_ms' => (int) env('MUSICBRAINZ_RATE_INTERVAL_MS', 1100),
    ],

    'cover_art_archive' => [
        'url' => env('COVER_ART_ARCHIVE_URL', 'https://coverartarchive.org'),
        'user_agent' => env('COVER_ART_ARCHIVE_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
        'timeout' => (int) env('COVER_ART_ARCHIVE_TIMEOUT_SECONDS', 30),
        'release_attempt_limit' => min(8, max(2, (int) env('COVER_ART_ARCHIVE_RELEASE_ATTEMPT_LIMIT', 5))),
        'missing_ttl_days' => min(365, max(1, (int) env('COVER_ART_ARCHIVE_MISSING_TTL_DAYS', 30))),
        'retry_ttl_hours' => min(168, max(1, (int) env('COVER_ART_ARCHIVE_RETRY_TTL_HOURS', 24))),
    ],

    'listenbrainz' => [
        'url' => env('LISTENBRAINZ_URL', 'https://api.listenbrainz.org'),
        'username' => env('LISTENBRAINZ_USERNAME'),
        'token' => env('LISTENBRAINZ_TOKEN'),
        'enabled' => filled(env('LISTENBRAINZ_USERNAME')) && filled(env('LISTENBRAINZ_TOKEN')),
        'timeout' => (int) env('LISTENBRAINZ_TIMEOUT_SECONDS', 30),
        'page_size' => min(1000, max(1, (int) env('LISTENBRAINZ_PAGE_SIZE', 1000))),
        'overlap_seconds' => max(0, (int) env('LISTENBRAINZ_OVERLAP_SECONDS', 172800)),
        'rate_interval_ms' => max(0, (int) env('LISTENBRAINZ_RATE_INTERVAL_MS', 250)),
        'user_agent' => env('LISTENBRAINZ_USER_AGENT', 'Disco/0.1 (https://github.com/cliophate/disco)'),
    ],

    'qobuz' => [
        'storefront' => env('QOBUZ_STOREFRONT', 'us-en'),
    ],

];

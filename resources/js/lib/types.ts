export type MetadataStatus = 'enriched' | 'identified' | 'candidate';
export type IdentityStatus = 'confirmed' | 'candidate';
export type DiscoverySource = 'plex' | 'listenbrainz' | 'musicbrainz' | (string & {});

export interface User {
    id: string;
    name?: string;
    email: string;
    unread_notification_count: number;
}

export type NotificationFilter = 'all' | 'unread' | 'active';
export type UpcomingNotificationStatus = 'active' | 'withdrawn' | 'resolved';

export interface UpcomingNotification {
    id: string;
    release_group_id: string;
    artist: string;
    title: string;
    release_date: string;
    primary_type: string;
    personalization: { match: 'followed' | 'library' | 'followed_and_library' | 'none'; reason: string };
    source: { provider: string; provider_name: string; url: string; snapshot_id: string };
    status: UpcomingNotificationStatus;
    resolution_reason: 'source_absent' | 'outside_horizon' | 'owned' | 'released' | 'no_longer_personalized' | null;
    status_detail: string;
    read: boolean;
    read_at: string | null;
    links: { album: string; upcoming: string };
    created_at: string;
    updated_at: string;
}

export interface NotificationResponse {
    data: UpcomingNotification[];
    meta: PaginationMeta & { filter: NotificationFilter };
    links: PaginationLinks;
}

export interface ArtworkImage {
    id: string;
    url: string;
    width: number | null;
    height: number | null;
}

export interface PartialDate {
    year: number;
    month: number | null;
    day: number | null;
    precision: 'year' | 'month' | 'day';
}

export interface ArtistSummary {
    id: string | null;
    name: string;
    credited_name?: string | null;
    portrait: ArtworkImage | null;
    type: string | null;
    area: string | null;
    genres: string[];
}

export interface ArtistPage {
    data: (ArtistSummary & { id: string })[];
    meta: PaginationMeta & {
        filters: { all: number; person: number; group: number; other: number };
        sort: 'name' | '-name';
        filter: 'all' | 'person' | 'group' | 'other';
    };
    links: PaginationLinks;
}

export interface SearchArtistSummary {
    id: string;
    name: string;
    portrait: ArtworkImage | null;
}

export interface ExternalCatalogResult {
    mbid: string;
    title: string;
    artist: string;
    first_release_date: string | null;
    primary_type: 'Album' | 'EP';
    disambiguation: string | null;
    artwork_status: string;
    entity_id: string | null;
    owned: boolean;
}

export interface Album {
    id: string;
    plex_item_id: string | null;
    title: string;
    artist: ArtistSummary | null;
    year: number | null;
    artwork: ArtworkImage | null;
    added_at: string | null;
    duration_ms: number | null;
    track_count: number | null;
    last_heard_at: string | null;
    play_count: number | null;
    listening_signals: {
        plex: {
            album_view_count: number;
            played_track_count: number;
            last_viewed_at: string | null;
        };
        listenbrainz: {
            listen_count: number;
            first_listened_at: string | null;
            last_listened_at: string | null;
        };
    } | null;
    release_type: string | null;
    first_release_date: PartialDate | null;
    genres: string[];
    genre_basis: 'album' | 'artist' | null;
    labels: { name: string; catalog_number: string | null }[];
    disambiguation: string | null;
    sources: string[];
    owned: boolean;
    metadata_status: MetadataStatus;
    identity_status: IdentityStatus;
    open_in_plex_available: boolean;
    open_in_plex_status: 'exact' | 'choice-required' | 'unavailable';
    qobuz_search_url: string;
    qobuz?: QobuzDestination;
    list_state?: AlbumListState | null;
}

export type AlbumListStatus = 'want_to_listen' | 'listened' | 'removed';
export interface AlbumListState {
    id: string;
    album_id: string;
    status: AlbumListStatus;
    note: string | null;
    source: string | null;
    wanted_at: string | null;
    listened_at: string | null;
    removed_at: string | null;
    state_changed_at: string;
    updated_at: string;
}

export interface AlbumListPage {
    data: Album[];
    meta: PaginationMeta & {
        filter: 'want_to_listen' | 'listened' | 'removed' | 'all';
        ownership: 'all' | 'owned' | 'outside';
        sort: '-changed' | 'changed' | 'name' | '-name';
        filters: Record<'all' | 'want_to_listen' | 'listened' | 'removed', number>;
        ownership_filters: Record<'all' | 'owned' | 'outside', number>;
    };
    links: PaginationLinks;
}

export interface CreditItem {
    name: string;
    target: { id: string; kind: string; name: string } | null;
    relationship_type: string;
    attributes: string[];
    via_work?: { id: string; name: string } | null;
    provenance: { provider: string; url: string | null; retrieved_at: string | null };
    portrait?: ArtworkImage | null;
}

export interface CreditGroup {
    role: 'performer' | 'producer' | 'songwriter' | 'engineer' | 'work' | 'other';
    label: string;
    items: CreditItem[];
}

export interface CreditCollection {
    status: 'available' | 'unavailable';
    groups: CreditGroup[];
}

export interface Track {
    id: string;
    disc: number;
    position: number;
    title: string;
    duration_ms: number | null;
    featured_artists: { id: string | null; name: string }[];
    credits: CreditCollection;
    listening: {
        identity_status: 'exact' | 'unmatched';
        plex: TrackListeningEvidence;
        listenbrainz: TrackListeningEvidence;
    };
    playback?: {
        plex_item_id: string;
        sources: PlaybackSource[];
    };
}

export interface PlaybackSource {
    id: string;
    mime_type: string;
    container: string | null;
    codec: string | null;
    channels: number | null;
    bit_depth: number | null;
    sample_rate_hz: number | null;
}

export interface PlaybackSession {
    id: string;
    stream_url: string;
    expires_at: string;
}

export interface TrackListeningEvidence {
    status: 'counted' | 'known_zero' | 'unmatched_identity' | 'unsupported_source' | 'unavailable';
    play_count: number | null;
    first_listened_at: string | null;
    last_listened_at: string | null;
    availability_as_of: string | null;
    copy_count: number | null;
    aggregation: 'exact_copy' | 'maximum_across_exact_copies' | 'immutable_exact_events' | null;
}

export type FeedbackAction = 'interested' | 'not_for_me' | 'already_know' | 'wrong_match';

export interface AlbumHolding {
    id: string;
    release_id: string | null;
    plex_item_id: string;
    title: string;
    year: number | null;
    formats: string[];
    edition_summary: string | null;
}

export interface NarrativeDescription {
    text: string;
    language: string;
    provider: string;
    provider_name: string;
    source_url: string;
    license_name: string | null;
    license_url: string | null;
}

export interface DiscogsCatalogMetadata {
    object_type: 'artist' | 'master' | 'release';
    external_id: string;
    source_url: string;
    fetched_at: string;
    fields: {
        id: string;
        object_type: 'artist' | 'master' | 'release';
        name?: string;
        real_name?: string;
        name_variations?: string[];
        year?: number;
        released?: string;
        country?: string;
        genres?: string[];
        styles?: string[];
        formats?: { name?: string; quantity?: string; descriptions?: string[] }[];
        labels?: { id?: string; name?: string; catalog_number?: string }[];
        identifiers?: { type?: string; value?: string; description?: string }[];
    };
}

export interface AlbumDetail extends Album {
    basis_release_id: string | null;
    basis_plex_item_id: string | null;
    holdings: AlbumHolding[];
    tracks: Track[];
    formats: string[];
    credits: CreditCollection;
    recommendation: {
        item_id: string;
        reasons: RecommendationReason[];
        explanation_text: string;
        feedback: { action: FeedbackAction; reason: string | null; expires_at: string | null } | null;
    } | null;
    description: NarrativeDescription | null;
    plex_playback_context: PlexPlaybackContext;
    discogs: DiscogsCatalogMetadata | null;
}

export interface PlexPlaybackContext {
    status: 'currently_active' | 'recently_played' | 'available' | 'unavailable';
    basis: 'active_session' | 'plex_last_viewed' | 'active_holding';
    player_state: 'playing' | 'paused' | 'buffering' | null;
    observed_at: string | null;
    last_played_at: string | null;
    expires_at: string | null;
    availability_as_of: string | null;
}

export interface ExternalLink {
    type: string;
    label: string;
    url: string;
}

export interface ExternalLinkGroup {
    label: string;
    links: ExternalLink[];
}

export interface ExternalLinks {
    primary: ExternalLink[];
    groups: ExternalLinkGroup[];
}

export interface QobuzDestination {
    url: string;
    status: 'exact' | 'search';
    source: 'musicbrainz_url_relationship' | 'catalog_search';
}

export interface ArtistDetail extends ArtistSummary {
    id: string;
    follow_state: { explicit: boolean; implicit: boolean; seed: boolean };
    plex_item_id: string | null;
    open_in_plex_available: boolean;
    open_in_plex_status: 'exact' | 'unavailable';
    begin_date: PartialDate | null;
    end_date: PartialDate | null;
    disambiguation: string | null;
    external_links: ExternalLinks;
    qobuz?: QobuzDestination;
    description: NarrativeDescription | null;
    relationships: {
        status: 'available' | 'unavailable';
        roles: string[];
        people: { id: string; name: string; portrait: ArtworkImage | null; roles: string[]; shared_credits: number }[];
        works: { id: string; name: string; relationship_type: string }[];
    };
    albums: Album[];
    recommended_albums: Album[];
    discogs: DiscogsCatalogMetadata | null;
}

export type ArtistDiscographyView = 'missing' | 'present' | 'all';
export type ArtistDiscographyTypes = 'albums' | 'albums_eps' | 'all';
export type ArtistDiscographyNoise = 'core' | 'all';

export interface ArtistDiscographyRefresh {
    status: 'idle' | 'queued' | 'running' | 'succeeded' | 'failed' | 'unavailable';
    requested_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    generation_id: string | null;
    message: string | null;
}

export interface ArtistDiscographyItem {
    id: string;
    album: Album;
    primary_type: string;
    secondary_types: string[];
    states: {
        holding: 'absent' | 'present';
        wanted: boolean;
        listened: boolean;
        recommended: boolean;
        upcoming: boolean;
        observed_listening: boolean;
        last_listened_at: string | null;
    };
    official_release_evidence: {
        status: 'official';
        release_mbid: string;
        release_date: string | null;
    };
}

export interface ArtistDiscographyResponse {
    data: ArtistDiscographyItem[];
    meta: PaginationMeta & {
        generation_id: string | null;
        generated_at: string | null;
        expires_at: string | null;
        status: 'ready' | 'stale' | 'empty';
        refresh: ArtistDiscographyRefresh;
        stale: boolean;
        truncated: boolean;
        source_total: number;
        view: ArtistDiscographyView;
        types: ArtistDiscographyTypes;
        noise: ArtistDiscographyNoise;
        counts: {
            views: Record<ArtistDiscographyView, number>;
            types: Record<ArtistDiscographyTypes, number>;
        };
    };
    links: PaginationLinks;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

export interface PaginationLinks {
    first: string;
    prev: string | null;
    next: string | null;
    last: string;
}

export interface AlbumPage {
    data: Album[];
    meta: PaginationMeta & { filters: Record<'all' | 'album' | 'ep' | 'single' | 'other', number>; filter: string; sort: string };
    links: PaginationLinks;
}

export interface SearchGroups {
    albums: Album[];
    artists: SearchArtistSummary[];
    meta?: { limit: number; truncated: boolean };
}

export interface RecommendationReason {
    code: string;
    text: string;
    source: DiscoverySource;
}

export interface Recommendation {
    album: Album;
    reasons: RecommendationReason[];
    lens?: string;
}

export interface BeyondRecommendation {
    item_id: string;
    entity_id: string;
    album: Album;
}

export interface BeyondResponse {
    data: BeyondRecommendation[];
    meta: PaginationMeta & { run_id: string | null; shuffle: string; filter: 'all' | 'album' | 'ep' | 'single'; filters: Record<'all' | 'album' | 'ep' | 'single', number>; eligible_total: number; run_total?: number };
    links: PaginationLinks;
}

export interface DiscoverySection {
    type: 'waiting' | 'rediscover' | 'recently-heard' | 'artist-trail' | 'quick-listen' | 'recently-added' | 'beyond-library';
    title: string;
    description: string;
    total?: number;
    items: Recommendation[];
}

export interface HomeLensResponse {
    data: Recommendation[];
    section: Omit<DiscoverySection, 'items' | 'total'>;
    meta: PaginationMeta & { version: string };
    links: PaginationLinks;
}

export interface HomeData {
    feature: Recommendation | null;
    sections: DiscoverySection[];
    recent_artists: ArtistSummary[];
    collection: { artists: number; albums: number; tracks: number };
    activity: CollectionActivityEvent[];
}

export interface CollectionActivityEvent {
    id: string;
    kind: 'played' | 'added';
    occurred_at: string;
    album: Album;
}

export interface HomeMeta {
    edition_id: string;
    edition_version: string;
    algorithm: string;
    generated_at: string;
    facts_as_of: string;
    last_plex_sync_at: string | null;
    last_listenbrainz_import_at: string | null;
    source_coverage: Record<string, number>;
    activity: {
        status: 'ready' | 'stale' | 'empty';
        stale: boolean;
        added_as_of: string | null;
        played_as_of: string | null;
    };
}

export interface HomeResponse {
    data: HomeData;
    meta: HomeMeta;
}

export type DiscoverSpan = 'standard' | 'wide' | 'tall' | 'feature';

export interface DiscoverAlbumItem {
    id: string;
    type: 'album';
    presentation: 'feature' | 'editorial' | 'cover' | 'overlay' | 'text';
    span: DiscoverSpan;
    lens: string;
    description: string | null;
    recommendation: Recommendation;
}

export interface DiscoverArtistItem {
    id: string;
    type: 'artist';
    presentation: 'portrait';
    span: DiscoverSpan;
    lens: string;
    artist: ArtistSummary & { id: string };
}

export interface DiscoverEditorialItem {
    id: string;
    type: 'editorial';
    presentation: 'story';
    span: DiscoverSpan;
    editorial: {
        id: string;
        source: 'pitchfork';
        publication: 'Pitchfork';
        publisher: string;
        headline: string;
        excerpt: string | null;
        author: string | null;
        category: string | null;
        published_at: string;
        url: string;
        image: { url: string; width: number | null; height: number | null } | null;
    };
}

export type DiscoverItem = DiscoverAlbumItem | DiscoverArtistItem | DiscoverEditorialItem;

export interface DiscoverResponse {
    data: DiscoverItem[];
    meta: PaginationMeta & {
        edition_id: string;
        edition_version: string;
        generated_at: string | null;
    };
    links: PaginationLinks;
}

export type UpcomingView = 'for-you' | 'all';

export interface UpcomingRelease {
    id: string;
    album: Album;
    release_date: string;
    primary_type: 'Album' | 'EP' | 'album' | 'ep';
    secondary_types: string[];
    artwork_status: 'available' | 'unavailable';
    musicbrainz: {
        release_group_mbid: string;
        release_mbid: string;
        artist_mbids: string[];
    };
    personalization: {
        match: 'followed' | 'library' | 'followed_and_library' | null;
        reason: string | null;
    };
    provenance: {
        provider: 'listenbrainz';
        provider_name: string;
        source_url: string;
        source_snapshot_id: string;
        retrieved_at: string;
        identity_method: 'exact_musicbrainz_ids';
    };
}

export interface UpcomingResponse {
    data: UpcomingRelease[];
    meta: PaginationMeta & {
        generation_id: string | null;
        generated_at: string | null;
        expires_at: string | null;
        stale: boolean;
        status: 'ready' | 'stale' | 'empty';
        view: UpcomingView;
        horizon_days: 30 | 60 | null;
        horizon_reason: string;
        window_start: string | null;
        window_end: string | null;
        past_days: number | null;
        future_days: number | null;
        coverage: Record<string, number | string>;
    };
    links: PaginationLinks;
}

export interface MetadataEntityCoverage {
    type: 'artist' | 'album' | 'track';
    total: number;
    identified: number;
    missing_identity: number;
    enriched: number;
    artwork_ready: number;
    identity_percentage: number;
    statuses: Record<'identity' | 'enrichment' | 'artwork' | 'narrative', Partial<Record<MetadataDiagnosticStatus, number>>>;
}

export type MetadataDiagnosticCategory = 'identity' | 'enrichment' | 'artwork' | 'narrative';
export type MetadataDiagnosticStatus = 'ready' | 'missing' | 'failed' | 'stale' | 'pending' | 'ambiguous';

export interface MetadataDiagnosticRow {
    id: string;
    type: 'artist' | 'album' | 'track';
    category: MetadataDiagnosticCategory;
    status: MetadataDiagnosticStatus;
    title: string;
    provider: string | null;
    last_attempt_at: string | null;
    failure_category: string | null;
    next_retry_at: string | null;
    retry_supported: boolean;
    repair_note: string | null;
}

export interface MetadataDiagnosticsResponse {
    data: MetadataDiagnosticRow[];
    meta: PaginationMeta;
    links: PaginationLinks;
}

export interface MetadataCoverage {
    entities: MetadataEntityCoverage[];
    overall: { total: number; identified: number };
    pipelines: MetadataPipelineStatus[];
    last_plex_sync_at: string | null;
    listenbrainz: {
        enabled: boolean;
        username: string | null;
        observations: number;
        current_listens: number;
        recording_matched: number;
        album_matched: number;
        unmatched: number;
        conflicts: number;
        album_match_percentage: number;
        latest_listened_at: string | null;
        last_import_at: string | null;
        last_import_status: string | null;
        last_full_import_at: string | null;
    };
}

export interface MetadataPipelineStatus {
    key: string;
    name: string;
    provider: string;
    status: 'healthy' | 'building' | 'attention' | 'idle' | 'disabled';
    detail: string;
    cadence: string;
    last_activity_at: string | null;
    next_run_at: string;
    metrics: Array<{ label: string; value: number | string; status?: PipelineDiagnosticStatus }>;
}

export type PipelineDiagnosticStatus = 'exact' | 'fresh' | 'stale' | 'ambiguous' | 'missing' | 'conflict' | 'failed' | 'queued' | 'ready';

export interface PipelineDiagnosticRow {
    id: string;
    pipeline: 'discogs' | 'discographies' | 'discography-artwork';
    status: PipelineDiagnosticStatus;
    title: string;
    subject_type: 'artist' | 'album';
    provider: string;
    source_basis: string;
    record_url: string;
    last_attempt_at: string | null;
    failure_category: string | null;
    next_retry_at: string | null;
    retry_supported: boolean;
    repair_note: string | null;
}

export interface PipelineDiagnosticsResponse {
    data: PipelineDiagnosticRow[];
    meta: PaginationMeta;
    links: PaginationLinks;
}

export type AdminPipeline = MetadataPipelineStatus;

export interface AdminOperation {
    id: string;
    operation_key: string;
    status: string;
    result: Record<string, unknown> | null;
    error_code: string | null;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
}

export interface AdminProvider {
    provider: string;
    source: 'database' | 'environment' | 'missing' | (string & {});
    configured: boolean;
    tested_at: string | null;
}

export interface AdminOverview {
    pipelines: AdminPipeline[];
    operations: AdminOperation[];
    failed_jobs: number;
    providers: AdminProvider[];
}

export type AdminProvidersResponse = AdminProvider[];

export type AdminOperationsResponse = AdminOperation[];

export interface AdminProviderCredential {
    secret: string;
    current_password: string;
}

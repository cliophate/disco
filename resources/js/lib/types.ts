export interface User { id: string; name?: string; email: string }
export interface ArtworkImage { url: string; width: number | null; height: number | null }
export interface PartialDate { year: number; month: number | null; day: number | null; precision: 'year' | 'month' | 'day' }
export interface Pick {
    id: string; artist: string; title: string; year: string | null; type: 'Album' | 'EP';
    reason: string; state: 'new' | 'in_plex' | 'played'; qobuz_url: string; qobuz_status: 'exact' | 'search';
    release_date: string | null; upcoming: boolean; artwork: ArtworkImage | null;
}
export interface DiscoverySection {
    key: 'new_artists' | 'new_releases' | 'more'; generated_at: string | null;
    stale: boolean; message: string | null; picks: Pick[]; earlier: Pick[];
}
export type AdminProvider = 'plex' | 'listenbrainz';
export interface AdminProviderCredential { provider: AdminProvider; configured: boolean; source: string; tested_at: string | null }
export interface AdminOverview {
    providers: AdminProviderCredential[];
    pipelines: { name: string; status: string; completed_at: string | null; summary?: { provider_failures?: number } | null }[];
}

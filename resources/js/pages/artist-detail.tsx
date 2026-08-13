import { useInfiniteQuery, useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { AlbumCard } from '../components/album-card';
import { ArtistPortrait } from '../components/artist-portrait';
import { ArtistFollowButton } from '../components/artist-follow-button';
import { AttributedNarrative } from '../components/attributed-narrative';
import { DetailTabs } from '../components/detail-tabs';
import { DiscogsAttribution } from '../components/discogs-attribution';
import { EntityPortraitLink } from '../components/entity-portrait-link';
import { FactList } from '../components/fact-list';
import { FilterBar } from '../components/filter-bar';
import { FinitePageSentinel } from '../components/finite-page-sentinel';
import { OpenInPlexButton } from '../components/open-in-plex-button';
import { AlbumGridSkeleton, EmptyState, ErrorState } from '../components/states';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';
import type { ArtistDetail, ArtistDiscographyNoise, ArtistDiscographyTypes, ArtistDiscographyView, ExternalLinks, QobuzDestination } from '../lib/types';
import { formatPartialDate } from '../lib/utils';

const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function ArtistLinks({ links, qobuz }: { links: ExternalLinks; qobuz?: QobuzDestination }) {
    const primary = links.primary.filter((link) => link.type !== 'qobuz');
    const groups = links.groups.map((group) => ({ ...group, links: group.links.filter((link) => link.type !== 'qobuz') })).filter((group) => group.links.length > 0);
    if (primary.length === 0 && groups.length === 0 && !qobuz) return null;

    return (
        <section aria-labelledby="artist-destinations-title" className="mt-10">
            <p className="editorial-eyebrow text-cobalt">Destinations</p>
            <h2 id="artist-destinations-title" className="mt-2 font-serif text-2xl font-bold">Elsewhere</h2>
            <div className="mt-4 divide-y divide-line border-t border-line">
                {qobuz && <a href={qobuz.url} target="_blank" rel="noreferrer" className="flex min-h-11 items-center justify-between gap-3 py-3 text-sm font-semibold text-cobalt outline-none hover:underline focus-visible:ring-2 focus-visible:ring-cobalt"><span>{qobuz.status === 'exact' ? 'Open artist on Qobuz' : 'Search Qobuz'}<span className="mt-0.5 block text-xs font-normal text-fog">{qobuz.status === 'exact' ? 'Exact MusicBrainz-linked destination' : 'Catalogue search; availability not checked'}</span></span><ExternalLink className="size-3.5 shrink-0" /></a>}
                {primary.map((link) => (
                    <a key={`${link.type}-${link.url}`} href={link.url} target="_blank" rel="noreferrer" className="flex min-h-11 items-center justify-between gap-3 py-3 text-sm font-semibold text-cobalt outline-none hover:underline focus-visible:ring-2 focus-visible:ring-cobalt">
                        {link.label} <ExternalLink className="size-3.5 shrink-0" />
                    </a>
                ))}
            </div>
            {groups.length > 0 && (
                <details className="mt-3 text-xs text-fog">
                    <summary className="flex min-h-11 w-fit cursor-pointer items-center font-bold text-cobalt hover:underline">More links</summary>
                    <div className="mt-4 grid gap-6 sm:grid-cols-2">
                        {groups.map((group) => (
                            <div key={group.label}>
                                <p className="font-bold uppercase tracking-[0.12em] text-ink">{group.label}</p>
                                <div className="mt-2 divide-y divide-line border-t border-line">
                                    {group.links.map((link) => <a key={`${link.type}-${link.url}`} href={link.url} target="_blank" rel="noreferrer" className="flex min-h-11 items-center justify-between gap-2 py-2 text-cobalt hover:underline">{link.label}<ExternalLink className="size-3 shrink-0" /></a>)}
                                </div>
                            </div>
                        ))}
                    </div>
                </details>
            )}
        </section>
    );
}

function hasExplicitLandscape(artist: ArtistDetail) {
    const portrait = artist.portrait;
    if (!portrait || portrait.width === null || portrait.height === null) return false;

    return portrait.width >= 1200 && portrait.height >= 500 && portrait.width / portrait.height >= 1.6;
}

function ArtistActions({ artist, dark = false }: { artist: ArtistDetail; dark?: boolean }) {
    const exactPlexTarget = artist.open_in_plex_available && artist.open_in_plex_status === 'exact'
        ? artist.plex_item_id
        : null;

    return (
        <div className="flex flex-wrap items-start gap-3">
            {exactPlexTarget && <OpenInPlexButton plexItemId={exactPlexTarget} primary />}
            <ArtistFollowButton artistId={artist.id} state={artist.follow_state} dark={dark} secondary={exactPlexTarget !== null} />
        </div>
    );
}

function IdentityHeader({ artist }: { artist: ArtistDetail }) {
    const [failedLandscapeUrl, setFailedLandscapeUrl] = useState<string | null>(null);
    const years = [formatPartialDate(artist.begin_date), formatPartialDate(artist.end_date)].filter(Boolean).join('–');
    const identity = [artist.area, years].filter(Boolean).join(' · ');
    const detail = (dark: boolean) => <>{artist.disambiguation && artist.disambiguation !== artist.name && <p className={`font-serif text-lg italic ${dark ? 'text-white/75' : 'text-fog'}`}>{artist.disambiguation}</p>}{artist.genres.length > 0 && <ul aria-label="Genres" className="mt-4 flex flex-wrap gap-x-4 gap-y-2">{artist.genres.map((genre) => <li key={genre} className={`border-b border-coral/50 pb-0.5 text-xs font-semibold uppercase tracking-[0.12em] ${dark ? 'text-white/70' : 'text-fog'}`}>{genre}</li>)}</ul>}</>;
    const credit = artist.credited_name && <p className="mt-3 max-h-14 overflow-hidden text-xs text-white/65 [contain:paint]">Credited as <bdi>{artist.credited_name}</bdi></p>;

    if (hasExplicitLandscape(artist) && artist.portrait!.url !== failedLandscapeUrl) {
        return (
            <header className="relative isolate mt-8 min-h-[24rem] overflow-hidden border-b border-line bg-[#17191f] text-[#fff8e9] sm:min-h-[30rem]" data-testid="artist-banner">
                <img src={artist.portrait!.url} alt={`${artist.name} artist image`} width={artist.portrait!.width ?? undefined} height={artist.portrait!.height ?? undefined} className="absolute inset-0 size-full object-cover" decoding="async" fetchPriority="high" onError={() => setFailedLandscapeUrl(artist.portrait!.url)} />
                <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/25 to-black/10" />
                <div className="relative flex min-h-[24rem] flex-col justify-end p-7 sm:min-h-[30rem] sm:p-12">
                    <div className="editorial-eyebrow mb-3 text-coral">Artist file{artist.type ? <span className="text-white/60"> · {artist.type}</span> : null}</div>
                    <h1 className="editorial-title max-w-5xl break-words [overflow-wrap:anywhere] text-5xl text-white sm:text-8xl">{artist.name}</h1>
                    {credit}
                    {identity && <p className="mt-4 font-serif text-xl text-white/80 sm:text-2xl">{identity}</p>}
                    <div className="mt-4 max-w-3xl">{detail(true)}</div>
                    <div className="mt-7"><ArtistActions artist={artist} dark /></div>
                </div>
            </header>
        );
    }

    return (
        <header className="mt-8 flex flex-col overflow-hidden border-y border-line bg-[#17191f] text-[#fff8e9] lg:grid lg:grid-cols-12 lg:items-stretch" data-testid="artist-split-hero">
            <div className="flex min-w-0 flex-col justify-center px-6 py-9 sm:px-10 sm:py-12 lg:col-span-7 lg:px-12 xl:px-16">
                <p className="editorial-eyebrow text-coral">Artist file{artist.type ? <span className="text-white/60"> · {artist.type}</span> : null}</p>
                <h1 className="editorial-title mt-3 break-words [overflow-wrap:anywhere] text-5xl text-white sm:text-7xl xl:text-8xl">{artist.name}</h1>
                {credit}
                {identity && <p className="mt-5 font-serif text-xl text-white/75 sm:text-2xl">{identity}</p>}
                <div className="mt-5 max-w-2xl">{detail(true)}</div>
                <div className="mt-8"><ArtistActions artist={artist} dark /></div>
            </div>
            <div className="order-first grid min-w-0 place-items-center bg-raised lg:col-span-5 lg:order-last">
                <ArtistPortrait portrait={artist.portrait?.url === failedLandscapeUrl ? null : artist.portrait} name={artist.name} variant="hero" priority className="w-full max-w-[34rem] lg:max-w-none" />
            </div>
        </header>
    );
}

function Overview({ artist, tabbed }: { artist: ArtistDetail; tabbed: boolean }) {
    const years = [formatPartialDate(artist.begin_date), formatPartialDate(artist.end_date)].filter(Boolean).join('–');
    const facts = [
        { id: 'type', label: 'Type', value: artist.type },
        { id: 'years', label: artist.end_date ? 'Active' : 'Since', value: years },
        { id: 'area', label: 'Area', value: artist.area },
        { id: 'discogs-real-name', label: 'Discogs real name', value: artist.discogs?.fields.real_name },
        { id: 'discogs-name-variations', label: 'Discogs name variants', value: artist.discogs?.fields.name_variations?.join(' / ') },
        { id: 'owned', label: 'In collection', value: `${artist.albums.length} ${artist.albums.length === 1 ? 'album' : 'albums'}` },
        { id: 'outside', label: 'Beyond', value: artist.recommended_albums.length > 0 ? `${artist.recommended_albums.length} ${artist.recommended_albums.length === 1 ? 'album' : 'albums'}` : null },
    ];

    return (
        <div id="artist-overview-panel" role={tabbed ? 'tabpanel' : undefined} aria-labelledby={tabbed ? 'artist-overview-tab' : undefined} className="grid items-start gap-12 pt-10 lg:grid-cols-[minmax(0,3fr)_minmax(15rem,1fr)] xl:gap-16">
            <div className="min-w-0">
                {artist.description ? <AttributedNarrative description={artist.description} eyebrow="Artist context" title={`About ${artist.name}`} titleId="artist-description-title" /> : <p className="max-w-2xl text-sm leading-7 text-fog">No attributed artist biography is available yet.</p>}
                {artist.relationships.status === 'available' && <RelationshipModules artist={artist} />}
            </div>
            <aside className="border-t-2 border-cobalt bg-raised/65 p-6 lg:border-t-0 lg:border-l-2" aria-labelledby="artist-facts-title">
                <p className="editorial-eyebrow text-cobalt">At a glance</p>
                <h2 id="artist-facts-title" className="mt-2 font-serif text-2xl font-bold">Artist facts</h2>
                <div className="mt-6"><FactList label="Artist facts" facts={facts} empty="No artist facts are available." /></div>
                {artist.discogs && <DiscogsAttribution sourceUrl={artist.discogs.source_url} />}
                <ArtistLinks links={artist.external_links} qobuz={artist.qobuz} />
            </aside>
        </div>
    );
}

function RelationshipModules({ artist }: { artist: ArtistDetail }) {
    const pictured = artist.relationships.people.filter((person) => person.portrait !== null);
    const inline = artist.relationships.people.filter((person) => person.portrait === null);
    const roleLabel = (roles: string[]) => roles.map((role) => role === 'songwriter' ? 'Songwriting' : role.charAt(0).toUpperCase() + role.slice(1)).join(' · ');

    return (
        <section className="mt-14" aria-labelledby="artist-relationships-title">
            <p className="editorial-eyebrow text-coral">Source-backed relationships</p>
            <h2 id="artist-relationships-title" className="mt-2 font-serif text-3xl font-bold">Related people &amp; works</h2>
            {pictured.length > 0 && <div className="mt-5 grid gap-x-8 sm:grid-cols-2">{pictured.map((person) => <EntityPortraitLink key={person.id} to={`/artists/${person.id}`} name={person.name} portrait={person.portrait} detail={`${roleLabel(person.roles)} · ${person.shared_credits} shared ${person.shared_credits === 1 ? 'credit' : 'credits'}`} />)}</div>}
            {inline.length > 0 && <ul className="mt-5 divide-y divide-line border-t border-line">{inline.map((person) => <li key={person.id} className="grid gap-1 py-3 sm:grid-cols-[minmax(10rem,1fr)_2fr]"><Link to={`/artists/${person.id}`} className="font-semibold text-cobalt hover:underline">{person.name}</Link><span className="text-xs leading-5 text-fog">{roleLabel(person.roles)} · {person.shared_credits} shared {person.shared_credits === 1 ? 'credit' : 'credits'}</span></li>)}</ul>}
            {artist.relationships.works.length > 0 && <div className="mt-8"><h3 className="text-xs font-bold uppercase tracking-[0.16em] text-fog">Works</h3><ul className="mt-2 divide-y divide-line border-t border-line">{artist.relationships.works.map((work) => <li key={work.id} className="grid gap-1 py-3 sm:grid-cols-[minmax(10rem,1fr)_2fr]"><span className="font-semibold">{work.name}</span><span className="text-xs capitalize text-fog">{work.relationship_type}</span></li>)}</ul></div>}
            <p className="mt-4 text-xs text-fog">Exact MusicBrainz relationships only; names are never inferred from prose or titles.</p>
        </section>
    );
}

function Albums({ artist, tabbed, returnContext }: { artist: ArtistDetail; tabbed: boolean; returnContext: string }) {
    const albums = [...artist.albums, ...artist.recommended_albums.filter((candidate) => !artist.albums.some((owned) => owned.id === candidate.id))];

    return (
        <section id="artist-albums-panel" role={tabbed ? 'tabpanel' : undefined} aria-labelledby={tabbed ? 'artist-albums-tab' : undefined} className="pt-10">
            <div className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-ink pb-4"><div><p className="editorial-eyebrow text-coral">Catalog</p><h2 className="mt-2 font-serif text-4xl font-bold">Albums</h2></div><span className="text-xs text-fog">{artist.albums.length} owned · {artist.recommended_albums.length} beyond</span></div>
            <div className="cover-grid">{albums.map((album, index) => <AlbumCard key={album.id} album={album} index={index} state={{ from: returnContext, label: artist.name }} />)}</div>
        </section>
    );
}

function Discography({ artist, tabbed, returnContext }: { artist: ArtistDetail; tabbed: boolean; returnContext: string }) {
    const [params, setParams] = useSearchParams();
    const view: ArtistDiscographyView = params.get('collection') === 'present' ? 'present' : params.get('collection') === 'all' ? 'all' : 'missing';
    const types: ArtistDiscographyTypes = params.get('types') === 'albums_eps' ? 'albums_eps' : params.get('types') === 'all' ? 'all' : 'albums';
    const noise: ArtistDiscographyNoise = params.get('noise') === 'all' ? 'all' : 'core';
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const requestedGeneration = params.get('generation');
    const generation = requestedGeneration && uuidPattern.test(requestedGeneration) ? requestedGeneration : null;
    const discography = useInfiniteQuery({
        queryKey: ['artist-discography', artist.id, view, types, noise, page, generation],
        initialPageParam: page,
        queryFn: ({ pageParam }) => api.artistDiscography(artist.id, view, types, noise, pageParam, 24, generation),
        getNextPageParam: (last) => last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
        placeholderData: (previous) => previous,
        refetchInterval: (query) => {
            const state = query.state.data as { pages?: { meta?: { refresh?: { status?: string } } }[] } | undefined;
            return ['queued', 'running'].includes(state?.pages?.[0]?.meta?.refresh?.status ?? '') ? 2000 : false;
        },
    });
    const firstPage = discography.data?.pages[0];
    useEffect(() => {
        const refreshed = firstPage?.meta.refresh.status === 'succeeded' ? firstPage.meta.refresh.generation_id : null;
        const resolved = refreshed ?? firstPage?.meta.generation_id ?? null;
        const resolvedPage = firstPage?.meta.current_page;
        if ((!resolved || resolved === requestedGeneration) && (!resolvedPage || resolvedPage === page)) return;
        const next = new URLSearchParams(params);
        if (resolved) next.set('generation', resolved);
        if (resolvedPage && resolvedPage !== page) resolvedPage === 1 ? next.delete('page') : next.set('page', String(resolvedPage));
        setParams(next, { replace: true });
    }, [firstPage?.meta.current_page, firstPage?.meta.generation_id, firstPage?.meta.refresh.generation_id, firstPage?.meta.refresh.status, page, params, requestedGeneration, setParams]);
    const updateFilter = (key: string, value: string, defaultValue: string) => {
        const next = new URLSearchParams(params);
        value === defaultValue ? next.delete(key) : next.set(key, value);
        next.delete('page');
        setParams(next);
    };
    const meta = firstPage?.meta;
    const refresh = meta?.refresh;
    const refreshMutation = useMutation({
        mutationFn: () => api.refreshArtistDiscography(artist.id),
        onSuccess: () => discography.refetch(),
    });
    const items = discography.data?.pages.flatMap((result) => result.data) ?? [];
    const fetchNext = () => discography.fetchNextPage().catch(() => undefined);

    return (
        <section id="artist-discography-panel" role={tabbed ? 'tabpanel' : undefined} aria-labelledby={tabbed ? 'artist-discography-tab' : undefined} className="pt-10">
            <div className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-ink pb-4"><div><p className="editorial-eyebrow text-coral">Exact catalog</p><h2 className="mt-2 font-serif text-4xl font-bold">Discography gaps</h2></div><div className="flex flex-wrap items-center justify-end gap-3">{meta && <span className="text-xs text-fog">{meta.total.toLocaleString()} matching · {meta.truncated ? 'bounded source browse' : `${meta.source_total.toLocaleString()} source groups`}</span>}{refresh && refresh.status !== 'unavailable' && <Button variant="secondary" size="sm" disabled={refreshMutation.isPending || refresh.status === 'queued' || refresh.status === 'running'} onClick={() => refreshMutation.mutate()}>{refreshMutation.isPending || refresh.status === 'queued' || refresh.status === 'running' ? 'Refreshing…' : 'Refresh now'}</Button>}</div></div>
            {meta?.stale && <p className="mb-6 border border-coral/40 bg-coral/10 px-4 py-3 text-sm text-ink" role="status">Showing the last complete cached discography.{refresh?.status === 'queued' || refresh?.status === 'running' ? ' A newer generation is being prepared in the background.' : ' Its refresh is overdue.'}</p>}
            {(refresh?.status === 'queued' || refresh?.status === 'running') && <p className="mb-6 border border-cobalt/30 bg-cobalt/5 px-4 py-3 text-sm text-ink" role="status">Refreshing the exact MusicBrainz discography in the background. This page will update when it is complete.</p>}
            {refresh?.status === 'failed' && <p className="mb-6 border border-coral/40 bg-coral/10 px-4 py-3 text-sm text-ink" role="alert">The latest discography refresh failed. The last complete cache remains available.</p>}
            {refreshMutation.isError && <p className="mb-6 text-sm text-coral" role="alert">{refreshMutation.error.message}</p>}
            {meta?.truncated && <p className="mb-6 border border-line bg-raised px-4 py-3 text-sm text-ink" role="status">This unusually large discography reached the configured browse limit. The visible identities are exact, but later source pages are not represented.</p>}
            {meta && <div className="grid gap-x-12 border-b border-line xl:grid-cols-2" data-testid="discography-filters">
                <div><p className="pt-4 text-xs font-bold uppercase tracking-[0.16em] text-fog">Collection state</p><FilterBar className="border-b-0" label="Collection state" selected={view} onFilterChange={(value) => updateFilter('collection', value, 'missing')} filters={[
                    { id: 'missing', label: 'Missing', count: meta.counts.views.missing },
                    { id: 'present', label: 'In collection', count: meta.counts.views.present },
                    { id: 'all', label: 'All', count: meta.counts.views.all },
                ]} /></div>
                <div><p className="pt-4 text-xs font-bold uppercase tracking-[0.16em] text-fog">Release scope</p><FilterBar className="border-b-0" label="Release types" selected={types} onFilterChange={(value) => updateFilter('types', value, 'albums')} filters={[
                    { id: 'albums', label: 'Albums', count: meta.counts.types.albums },
                    { id: 'albums_eps', label: 'Albums + EPs', count: meta.counts.types.albums_eps },
                    { id: 'all', label: 'All types', count: meta.counts.types.all },
                ]} disclosure={<Button variant="ghost" size="sm" aria-pressed={noise === 'all'} onClick={() => updateFilter('noise', noise === 'all' ? 'core' : 'all', 'core')}>{noise === 'all' ? 'Hide live, remix and compilation noise' : 'Include live, remix and compilation releases'}</Button>} /></div>
            </div>}
            {discography.isLoading && <div className="cover-grid mt-8" role="status" aria-label="Loading artist discography">{Array.from({ length: 6 }, (_, index) => <div key={index}><Skeleton className="aspect-square rounded-none" /><Skeleton className="mt-3 h-5 w-4/5" /></div>)}</div>}
            {discography.isError && !discography.data && <div className="mt-8"><ErrorState error={discography.error} retry={() => discography.refetch()} /></div>}
            {items.length > 0 && <div className="cover-grid mt-8">{items.map((item, index) => (
                <AlbumCard
                    key={item.id}
                    album={item.album}
                    index={index}
                    metadata={<div className="mt-3 flex flex-wrap gap-2" aria-label={`${item.album.title} states`}><Badge>{item.states.holding === 'present' ? 'In collection' : 'Missing'}</Badge>{item.states.wanted && <Badge>Wanted</Badge>}{item.states.listened && <Badge>Listened</Badge>}{item.states.observed_listening && <Badge>Heard</Badge>}{item.states.recommended && <Badge>Beyond pick</Badge>}{item.states.upcoming && <Badge>Upcoming</Badge>}<Badge>Official</Badge></div>}
                    state={{ from: returnContext, label: artist.name }}
                />
            ))}</div>}
            {discography.data && items.length === 0 && <div className="mt-10"><EmptyState
                title={meta?.status === 'empty' ? 'Discography not cached yet' : view === 'missing' ? 'No gaps in this view' : 'No releases in this view'}
                message={meta?.status === 'empty' ? 'The bounded scheduled refresh will publish this artist’s exact MusicBrainz discography without making this page wait on a provider.' : view === 'missing' ? (meta?.truncated ? 'No gaps were found in the bounded source pages currently cached.' : 'Every official core release matching these filters is present in the active library.') : 'Try a broader release type or include noisy secondary types.'}
            /></div>}
            {meta && (items.length > 0 || discography.hasNextPage) && <FinitePageSentinel hasNext={Boolean(discography.hasNextPage)} loading={discography.isFetchingNextPage} error={discography.isFetchNextPageError ? discography.error : null} loaded={items.length} total={meta.total} onLoadMore={fetchNext} onRetry={fetchNext} />}
            <p className="mt-10 border-t border-line pt-4 text-xs leading-5 text-fog">Release groups are exact MusicBrainz identities with an official release as evidence. Group dates describe the work; edition dates are shown only when that edition supplied one.</p>
        </section>
    );
}

export function ArtistDetailPage() {
    const { id = '' } = useParams();
    const location = useLocation();
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const artist = useQuery({ queryKey: ['artist', id], queryFn: () => api.artist(id) });
    useEffect(() => { if (artist.data?.id && artist.data.id !== id) navigate(`/artists/${artist.data.id}${location.search}`, { replace: true, state: location.state }); }, [artist.data?.id, id, location.search, location.state, navigate]);

    if (artist.isLoading) return <AlbumGridSkeleton count={6} />;
    if (artist.isError || !artist.data) return <ErrorState error={artist.error} retry={() => artist.refetch()} />;
    const data = artist.data;
    const routeState = location.state as { from?: unknown; label?: unknown } | null;
    const returnTo = typeof routeState?.from === 'string' && routeState.from.startsWith('/') && !routeState.from.startsWith('//') ? routeState.from : '/artists';
    const returnLabel = routeState?.label === 'search' ? 'search' : 'artists';
    const hasOverview = Boolean(data.description || data.type || data.area || data.begin_date || data.end_date || data.discogs || data.external_links.primary.length || data.external_links.groups.length || data.relationships.status === 'available');
    const hasAlbums = data.albums.length + data.recommended_albums.length > 0;
    const hasDiscography = true;
    const artistReturnContext = `${location.pathname}${location.search}`;
    const tabs = [
        ...(hasOverview ? [{ id: 'overview', label: 'Overview', panelId: 'artist-overview-panel', tabId: 'artist-overview-tab' }] : []),
        ...(hasAlbums ? [{ id: 'albums', label: 'Albums', count: data.albums.length + data.recommended_albums.length, panelId: 'artist-albums-panel', tabId: 'artist-albums-tab' }] : []),
        ...(hasDiscography ? [{ id: 'discography', label: 'Discography gaps', panelId: 'artist-discography-panel', tabId: 'artist-discography-tab' }] : []),
    ];
    const defaultView = tabs[0]?.id ?? 'discography';
    const requestedView = params.get('tab');
    const view = tabs.some((tab) => tab.id === requestedView) ? requestedView! : defaultView;
    const showTabs = tabs.length > 1;
    const updateView = (nextView: string) => {
        const next = new URLSearchParams(params);
        nextView === defaultView ? next.delete('tab') : next.set('tab', nextView);
        setParams(next);
    };

    return (
        <article className="min-w-0">
            <Link to={returnTo} className="inline-flex min-h-11 items-center gap-2 text-xs font-semibold text-fog outline-none hover:text-cobalt focus-visible:ring-2 focus-visible:ring-cobalt"><ArrowLeft className="size-4" />Back to {returnLabel}</Link>
            <IdentityHeader artist={data} />

            {showTabs && <DetailTabs mode="state" label="Artist detail" value={view} onValueChange={updateView} tabs={tabs} />}
            {hasOverview && (!showTabs || view === 'overview') && <Overview artist={data} tabbed={showTabs} />}
            {hasAlbums && (!showTabs || view === 'albums') && <Albums artist={data} tabbed={showTabs} returnContext={artistReturnContext} />}
            {hasDiscography && (!showTabs || view === 'discography') && <Discography artist={data} tabbed={showTabs} returnContext={artistReturnContext} />}
            {!hasOverview && !hasDiscography && <div className="mt-12"><EmptyState title="No catalog detail yet" message="This artist has a confirmed identity but no additional catalog detail." /></div>}
        </article>
    );
}

import { useQuery } from '@tanstack/react-query';
import { ExternalLink } from 'lucide-react';
import { useEffect } from 'react';
import { useLocation, useSearchParams } from 'react-router-dom';
import { AlbumListControl } from '../components/album-list-control';
import { BoundedPagination } from '../components/bounded-pagination';
import { CoverCard } from '../components/cover-card';
import { DetailTabs } from '../components/detail-tabs';
import { EmptyState, ErrorState } from '../components/states';
import { Button } from '../components/ui/button';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';
import type { UpcomingRelease, UpcomingView } from '../lib/types';

const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function UpcomingCard({ release, index, returnContext }: { release: UpcomingRelease; index: number; returnContext: string }) {
    const date = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(`${release.release_date}T00:00:00Z`));

    return (
        <CoverCard
            to={`/albums/${release.album.id}`}
            title={release.album.title}
            artist={release.album.artist?.name ?? 'Unknown artist'}
            artistTo={release.album.artist?.id ? `/artists/${release.album.artist.id}` : undefined}
            artwork={release.album.artwork}
            date={date}
            collectionState="outside"
            index={index}
            state={{ from: returnContext, label: 'Release window' }}
            details={<>
                <p className="mt-2 text-xs font-bold uppercase tracking-[0.16em] text-fog">{release.primary_type}</p>
                {release.personalization.reason && <p className="mt-3 text-xs leading-5 text-fog">{release.personalization.reason}</p>}
            </>}
            overlayAction={<AlbumListControl albumId={release.album.id} initialState={release.album.list_state ?? null} iconOnly />}
            action={<div className="flex flex-wrap gap-2"><Button asChild variant="secondary" size="sm">
                    <a href={release.album.qobuz?.url ?? release.album.qobuz_search_url} target="_blank" rel="noreferrer" aria-label={`${release.album.qobuz?.status === 'exact' ? 'Open' : 'Search'} Qobuz for ${release.album.title}`}><ExternalLink className="size-4" />{release.album.qobuz?.status === 'exact' ? 'Open on Qobuz' : 'Search Qobuz'}</a>
                </Button></div>}
        />
    );
}

export function UpcomingPage() {
    const location = useLocation();
    const [params, setParams] = useSearchParams();
    const view: UpcomingView = params.get('view') === 'all' ? 'all' : 'for-you';
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const requestedGeneration = params.get('generation');
    const generation = requestedGeneration && uuidPattern.test(requestedGeneration) ? requestedGeneration : null;
    const upcoming = useQuery({
        queryKey: ['upcoming', view, generation, page],
        queryFn: () => api.upcoming(view, page, 24, generation),
        placeholderData: (previous, previousQuery) => previousQuery?.queryKey[1] === view ? previous : undefined,
    });
    const resolvedGeneration = upcoming.data?.meta.generation_id ?? null;

    useEffect(() => {
        if (!resolvedGeneration || (requestedGeneration === resolvedGeneration && (!upcoming.data || upcoming.data.meta.current_page === page))) return;
        const next = new URLSearchParams(params);
        next.set('generation', resolvedGeneration);
        if (upcoming.data && upcoming.data.meta.current_page !== page) upcoming.data.meta.current_page === 1 ? next.delete('page') : next.set('page', String(upcoming.data.meta.current_page));
        setParams(next, { replace: true });
    }, [page, params, requestedGeneration, resolvedGeneration, setParams, upcoming.data]);

    const updateView = (nextView: string) => {
        const next = new URLSearchParams(params);
        nextView === 'for-you' ? next.delete('view') : next.set('view', 'all');
        next.delete('page');
        if (resolvedGeneration) next.set('generation', resolvedGeneration);
        setParams(next);
    };
    const href = (target: number) => {
        const next = new URLSearchParams(params);
        if (resolvedGeneration) next.set('generation', resolvedGeneration);
        target === 1 ? next.delete('page') : next.set('page', String(target));
        return `?${next.toString()}`;
    };
    const returnContext = `${location.pathname}${location.search}`;

    if (upcoming.isLoading) return <div role="status" aria-label="Loading releases"><Skeleton className="h-48 rounded-none" /><div className="cover-grid mt-10">{Array.from({ length: 10 }, (_, index) => <div key={index}><Skeleton className="aspect-square rounded-none" /><Skeleton className="mt-3 h-5 w-4/5" /></div>)}</div></div>;
    if (upcoming.isError || !upcoming.data) return <ErrorState error={upcoming.error} retry={() => upcoming.refetch()} />;
    const { data, meta } = upcoming.data;

    return (
        <div>
            <header className="grid gap-6 py-7 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] md:items-end md:gap-12">
                <div><p className="editorial-eyebrow">Discover</p><h1 className="mt-3 break-words font-serif text-5xl font-bold leading-[0.92] tracking-[-0.045em] sm:text-6xl">Recent and upcoming</h1></div>
                <p className="max-w-3xl text-sm leading-6 text-fog sm:text-base sm:leading-7">Albums and EPs from the previous 30 days through the next 30, linked through exact MusicBrainz identities. Followed and held artists shape For you; All releases keeps the source broad and varied.</p>
            </header>
            <div className="mt-7"><DetailTabs mode="route" label="Discover views" tabs={[
                { id: 'edition', label: 'Current edition', to: '/discover' },
                { id: 'upcoming', label: 'Release window', to: '/discover/upcoming' },
            ]} /></div>
            <div className="mt-6"><DetailTabs mode="state" label="Release window views" value={view} onValueChange={updateView} tabs={[
                { id: 'for-you', label: 'For you', panelId: 'upcoming-panel', tabId: 'upcoming-for-you-tab' },
                { id: 'all', label: 'All releases', panelId: 'upcoming-panel', tabId: 'upcoming-all-tab' },
            ]} /></div>

            {meta.stale && <p className="mt-6 border border-coral/40 bg-coral/10 px-4 py-3 text-sm text-ink" role="status">Showing the last cached release generation. A scheduled refresh is overdue; no provider was contacted for this page.</p>}
            <section id="upcoming-panel" role="tabpanel" aria-labelledby={view === 'for-you' ? 'upcoming-for-you-tab' : 'upcoming-all-tab'} className="pt-8">
                {data.length > 0 ? <div className="cover-grid">{data.map((release, index) => <UpcomingCard key={release.id} release={release} index={index} returnContext={returnContext} />)}</div> : <div className="mt-2"><EmptyState
                    title={meta.status === 'empty' ? 'The release window is not cached yet' : view === 'for-you' ? 'No personal matches in this window' : 'No albums or EPs in this window'}
                    message={meta.status === 'empty' ? 'The scheduled ListenBrainz refresh will build a provider-free release cache.' : view === 'for-you' ? 'Follow an artist or add their music to the active library; All releases remains available for broader discovery.' : 'The cached source contained no eligible exact album or EP identities.'}
                /></div>}
            </section>
            <BoundedPagination current={meta.current_page} last={meta.last_page} href={href} label="Release pages" />
            <footer className="mt-12 border-t border-line pt-5 text-xs leading-5 text-fog">
                <div className="flex flex-wrap items-baseline justify-between gap-3"><p className="font-bold uppercase tracking-[0.16em]">{meta.total.toLocaleString()} {view === 'for-you' ? (meta.total === 1 ? 'personal match' : 'personal matches') : (meta.total === 1 ? 'release' : 'releases')} · {meta.past_days !== null && meta.future_days !== null ? `${meta.past_days} days back · ${meta.future_days} days ahead` : 'awaiting refresh'}</p><p className="max-w-2xl">{meta.horizon_reason}</p></div>
                <p className="mt-4">Dates, types, artwork availability, exact identities, and ListenBrainz provenance come from the pinned cache. Qobuz destinations are exact only when MusicBrainz supplies one unambiguous URL relationship; otherwise they remain labelled catalogue searches.</p>
            </footer>
        </div>
    );
}

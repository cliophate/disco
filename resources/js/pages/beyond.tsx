import { useInfiniteQuery } from '@tanstack/react-query';
import { Shuffle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useLocation, useSearchParams } from 'react-router-dom';
import { AlbumCard } from '../components/album-card';
import { FinitePageSentinel } from '../components/finite-page-sentinel';
import { FilterBar } from '../components/filter-bar';
import { PageHeading } from '../components/page-heading';
import { EmptyState, ErrorState } from '../components/states';
import { Button } from '../components/ui/button';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';

const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export function BeyondPage() {
    const location = useLocation();
    const [params, setParams] = useSearchParams();
    const [fallbackShuffle] = useState(() => crypto.randomUUID());
    const requestedShuffle = params.get('shuffle');
    const shuffle = requestedShuffle && uuidPattern.test(requestedShuffle) ? requestedShuffle : fallbackShuffle;
    const runId = params.get('run');
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const filter = ['album', 'ep', 'single'].includes(params.get('type') ?? '') ? params.get('type')! : 'all';
    const beyond = useInfiniteQuery({
        queryKey: ['beyond', shuffle, runId, filter, page],
        initialPageParam: page,
        queryFn: ({ pageParam }) => api.beyond(pageParam, 24, shuffle, runId, filter),
        getNextPageParam: (last) => last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
    });
    const firstPage = beyond.data?.pages[0];
    const resolvedRun = firstPage?.meta.run_id ?? null;

    useEffect(() => {
        if (requestedShuffle === shuffle && (!resolvedRun || runId === resolvedRun) && (!firstPage || firstPage.meta.current_page === page)) return;
        const next = new URLSearchParams(params);
        next.set('shuffle', shuffle);
        if (resolvedRun) next.set('run', resolvedRun);
        if (firstPage && firstPage.meta.current_page !== page) firstPage.meta.current_page === 1 ? next.delete('page') : next.set('page', String(firstPage.meta.current_page));
        setParams(next, { replace: true });
    }, [firstPage, page, params, requestedShuffle, resolvedRun, runId, setParams, shuffle]);

    const update = (changes: Record<string, string | null>) => { const next = new URLSearchParams(params); Object.entries(changes).forEach(([key, value]) => value === null ? next.delete(key) : next.set(key, value)); setParams(next); };
    const returnContext = `${location.pathname}${location.search}`;

    if (beyond.isLoading) return <div role="status" aria-label="Loading recommendations"><Skeleton className="h-48 rounded-none" /><div className="cover-grid mt-10">{Array.from({ length: 10 }, (_, index) => <div key={index}><Skeleton className="aspect-square rounded-none" /><Skeleton className="mt-3 h-5 w-4/5" /></div>)}</div></div>;
    if (beyond.isError && !beyond.data) return <ErrorState error={beyond.error} retry={() => beyond.refetch()} />;
    if (!beyond.data || !firstPage) return null;
    const data = beyond.data.pages.flatMap((result) => result.data);
    const meta = firstPage.meta;
    const fetchNext = () => beyond.fetchNextPage().catch(() => undefined);

    return (
        <div>
            <PageHeading eyebrow="Outside the collection" title="Beyond your library" description="A pinned, shuffled view of releases containing recordings ListenBrainz recommends. Open a release for its exact identity, evidence, track list, and feedback controls." />
            <FilterBar label="Release type" filters={[
                { id: 'all', label: 'All', count: meta.filters.all },
                { id: 'album', label: 'Albums', count: meta.filters.album },
                { id: 'ep', label: 'EPs', count: meta.filters.ep },
                { id: 'single', label: 'Singles', count: meta.filters.single },
            ]} selected={filter} onFilterChange={(value) => update({ type: value === 'all' ? null : value, page: null })} controls={<Button variant="secondary" onClick={() => update({ shuffle: crypto.randomUUID(), run: null, page: null })} disabled={beyond.isFetching}><Shuffle className={`size-4 ${beyond.isFetching ? 'animate-spin' : ''}`} aria-hidden="true" />{beyond.isFetching ? 'Shuffling...' : 'Shuffle again'}</Button>} />
            {data.length ? <><div className="cover-grid mt-8">{data.map((recommendation, index) => <AlbumCard key={recommendation.item_id} album={recommendation.album} index={index} state={{ from: returnContext, label: 'Beyond' }} />)}</div><FinitePageSentinel hasNext={Boolean(beyond.hasNextPage)} loading={beyond.isFetchingNextPage} error={beyond.isFetchNextPageError ? beyond.error : null} loaded={data.length} total={meta.total} onLoadMore={fetchNext} onRetry={fetchNext} /></> : <div className="mt-10"><EmptyState title={meta.eligible_total === 0 ? 'No external recommendations yet' : 'No recommendations of this type'} message={meta.eligible_total === 0 ? 'The next ListenBrainz recommendation refresh will populate this view.' : 'Choose another release type in this pinned recommendation run.'} /></div>}
        </div>
    );
}

import { useInfiniteQuery } from '@tanstack/react-query';
import { useLocation, useSearchParams } from 'react-router-dom';
import { AlbumCard } from '../components/album-card';
import { FinitePageSentinel } from '../components/finite-page-sentinel';
import { FilterBar } from '../components/filter-bar';
import { PageHeading } from '../components/page-heading';
import { AlbumGridSkeleton, EmptyState, ErrorState } from '../components/states';
import { api } from '../lib/api';

export function LibraryPage() {
    const location = useLocation();
    const [params, setParams] = useSearchParams();
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const filter = ['album', 'ep', 'single', 'other'].includes(params.get('type') ?? '') ? params.get('type')! : 'all';
    const sort = ['-name', 'newest', 'oldest'].includes(params.get('sort') ?? '') ? params.get('sort')! : 'name';
    const albums = useInfiniteQuery({ queryKey: ['albums', page, filter, sort], initialPageParam: page, queryFn: ({ pageParam }) => api.albums(pageParam, filter, sort), getNextPageParam: (last) => last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined });
    const firstPage = albums.data?.pages[0];
    const data = albums.data?.pages.flatMap((result) => result.data) ?? [];
    const update = (changes: Record<string, string | null>) => { const next = new URLSearchParams(params); Object.entries(changes).forEach(([key, value]) => value === null ? next.delete(key) : next.set(key, value)); setParams(next); };
    const returnContext = `${location.pathname}${location.search}`;
    const fetchNext = () => albums.fetchNextPage().catch(() => undefined);

    return (
        <div>
            <PageHeading eyebrow="The complete collection" title="Album library" description={firstPage ? `${firstPage.meta.total.toLocaleString()} albums in the active collection. Filter by release type or choose a stable shelf order.` : 'Every owned album, one finite shelf at a time.'} />
            {firstPage && <FilterBar label="Release type" filters={[
                { id: 'all', label: 'All', count: firstPage.meta.filters.all }, { id: 'album', label: 'Albums', count: firstPage.meta.filters.album }, { id: 'ep', label: 'EPs', count: firstPage.meta.filters.ep }, { id: 'single', label: 'Singles', count: firstPage.meta.filters.single }, { id: 'other', label: 'Other', count: firstPage.meta.filters.other },
            ]} selected={filter} onFilterChange={(value) => update({ type: value === 'all' ? null : value, page: null })} sort={{ label: 'Sort', value: sort, options: [{ value: 'name', label: 'A to Z' }, { value: '-name', label: 'Z to A' }, { value: 'newest', label: 'Newest release' }, { value: 'oldest', label: 'Oldest release' }], onChange: (value) => update({ sort: value === 'name' ? null : value, page: null }) }} />}
            {albums.isLoading ? <AlbumGridSkeleton /> : albums.isError && !albums.data ? <ErrorState error={albums.error} retry={() => albums.refetch()} /> : !data.length ? <EmptyState title="No albums on this shelf" message={page > 1 ? 'This page has no records. Return to the previous shelf.' : 'Your library is ready for its first record.'} /> : (
                <div>
                    <div className="cover-grid">{data.map((album, index) => <AlbumCard key={album.id} album={album} index={index} state={{ from: returnContext, label: 'albums' }} />)}</div>
                    <FinitePageSentinel hasNext={Boolean(albums.hasNextPage)} loading={albums.isFetchingNextPage} error={albums.isFetchNextPageError ? albums.error : null} loaded={data.length} total={firstPage?.meta.total ?? data.length} onLoadMore={fetchNext} onRetry={fetchNext} />
                </div>
            )}
        </div>
    );
}

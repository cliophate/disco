import { useInfiniteQuery } from '@tanstack/react-query';
import { useLocation, useSearchParams } from 'react-router-dom';
import { EntityPortraitLink } from '../components/entity-portrait-link';
import { FinitePageSentinel } from '../components/finite-page-sentinel';
import { FilterBar } from '../components/filter-bar';
import { PageHeading } from '../components/page-heading';
import { EmptyState, ErrorState } from '../components/states';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';

const filters = ['all', 'person', 'group', 'other'] as const;
const sorts = ['name', '-name'] as const;

export function ArtistsPage() {
    const location = useLocation();
    const [params, setParams] = useSearchParams();
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const requestedFilter = params.get('type');
    const filter = filters.includes(requestedFilter as typeof filters[number]) ? requestedFilter as typeof filters[number] : 'all';
    const requestedSort = params.get('sort');
    const sort = sorts.includes(requestedSort as typeof sorts[number]) ? requestedSort as typeof sorts[number] : 'name';
    const artists = useInfiniteQuery({ queryKey: ['artists', page, filter, sort], initialPageParam: page, queryFn: ({ pageParam }) => api.artists(pageParam, filter, sort), getNextPageParam: (last) => last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined });

    const update = (changes: Record<string, string | null>) => {
        const next = new URLSearchParams(params);
        Object.entries(changes).forEach(([key, value]) => value === null ? next.delete(key) : next.set(key, value));
        setParams(next);
    };

    if (artists.isLoading) return <div role="status" aria-label="Loading artists"><Skeleton className="h-44 rounded-none" /><div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 9 }, (_, index) => <Skeleton key={index} className="h-24 rounded-none" />)}</div></div>;
    if (artists.isError && !artists.data) return <ErrorState error={artists.error} retry={() => artists.refetch()} />;
    if (!artists.data) return null;

    const data = artists.data.pages.flatMap((result) => result.data);
    const meta = artists.data.pages[0].meta;
    const returnContext = `${location.pathname}${location.search}`;
    const fetchNext = () => artists.fetchNextPage().catch(() => undefined);

    return (
        <div>
            <PageHeading eyebrow="Collection index" title="Artists" description={`${meta.total.toLocaleString()} artists in this view, ordered ${sort === 'name' ? 'A to Z' : 'Z to A'}. Search remains available in the header for direct lookup.`} />
            <FilterBar
                label="Artist type"
                filters={[
                    { id: 'all', label: 'All', count: meta.filters.all },
                    { id: 'person', label: 'People', count: meta.filters.person, disabled: meta.filters.person === 0 && filter !== 'person' },
                    { id: 'group', label: 'Groups', count: meta.filters.group, disabled: meta.filters.group === 0 && filter !== 'group' },
                    { id: 'other', label: 'Other', count: meta.filters.other, disabled: meta.filters.other === 0 && filter !== 'other' },
                ]}
                selected={filter}
                onFilterChange={(value) => update({ type: value === 'all' ? null : value, page: null })}
                sort={{
                    label: 'Sort',
                    value: sort,
                    options: [{ value: 'name', label: 'A to Z' }, { value: '-name', label: 'Z to A' }],
                    onChange: (value) => update({ sort: value === 'name' ? null : value, page: null }),
                }}
            />

            {data.length > 0 ? (
                <div className="artist-index-grid mt-10 grid gap-x-8 sm:grid-cols-2 lg:grid-cols-3">
                    {data.map((artist) => (
                        <EntityPortraitLink
                            key={artist.id}
                            to={`/artists/${artist.id}`}
                            state={{ from: returnContext, label: 'artists' }}
                            name={artist.name}
                            portrait={artist.portrait}
                            detail={[artist.type, artist.area].filter(Boolean).join(' · ') || undefined}
                        />
                    ))}
                </div>
            ) : <div className="mt-10"><EmptyState title="No artists in this view" message="Choose another artist type to return to the collection index." /></div>}

            {data.length > 0 && <FinitePageSentinel hasNext={Boolean(artists.hasNextPage)} loading={artists.isFetchingNextPage} error={artists.isFetchNextPageError ? artists.error : null} loaded={data.length} total={meta.total} onLoadMore={fetchNext} onRetry={fetchNext} />}
        </div>
    );
}

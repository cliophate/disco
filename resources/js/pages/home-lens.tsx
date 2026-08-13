import { useInfiniteQuery } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { useEffect } from 'react';
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom';
import { AlbumCard } from '../components/album-card';
import { FinitePageSentinel } from '../components/finite-page-sentinel';
import { PageHeading } from '../components/page-heading';
import { EmptyState, ErrorState } from '../components/states';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';

export function HomeLensPage() {
    const location = useLocation();
    const { lens = '' } = useParams();
    const [params, setParams] = useSearchParams();
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const version = params.get('version');
    const result = useInfiniteQuery({ queryKey: ['home-lens', lens, page, version], initialPageParam: page, queryFn: ({ pageParam }) => api.homeLens(lens, pageParam, 24, version), getNextPageParam: (last) => last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined });
    const firstPage = result.data?.pages[0];
    const resolvedVersion = firstPage?.meta.version;
    useEffect(() => { if (resolvedVersion && (!version || (firstPage && firstPage.meta.current_page !== page))) { const next = new URLSearchParams(params); next.set('version', resolvedVersion); if (firstPage?.meta.current_page === 1) next.delete('page'); else if (firstPage) next.set('page', String(firstPage.meta.current_page)); setParams(next, { replace: true }); } }, [firstPage, page, params, resolvedVersion, setParams, version]);

    if (result.isLoading) return <div role="status" aria-label="Loading discovery lens"><Skeleton className="h-44 rounded-none" /><div className="cover-grid mt-10">{Array.from({ length: 10 }, (_, index) => <div key={index}><Skeleton className="aspect-square rounded-none" /><Skeleton className="mt-3 h-5 w-4/5" /></div>)}</div></div>;
    if (result.isError && !result.data) return <ErrorState error={result.error} retry={() => result.refetch()} />;
    if (!result.data || !firstPage) return null;

    const data = result.data.pages.flatMap((entry) => entry.data);
    const { section, meta } = firstPage;
    const returnContext = `${location.pathname}${location.search}`;
    const fetchNext = () => result.fetchNextPage().catch(() => undefined);

    return (
        <div>
            <Link to="/" className="inline-flex min-h-11 items-center gap-2 text-xs font-semibold text-fog outline-none hover:text-cobalt focus-visible:ring-2 focus-visible:ring-cobalt"><ArrowLeft className="size-4" aria-hidden="true" />Back to Home</Link>
            <PageHeading eyebrow="Discovery lens" title={section.title} description={`${section.description} ${meta.total.toLocaleString()} ${meta.total === 1 ? 'album' : 'albums'} currently match this definition.`} />
            {data.length > 0 ? (
                <div className="cover-grid">
                    {data.map((recommendation, index) => <AlbumCard key={recommendation.album.id} album={recommendation.album} index={index} reasons={recommendation.reasons} state={{ from: returnContext, label: section.title }} />)}
                </div>
            ) : <EmptyState title="No albums on this page" message="The underlying collection facts changed or this lens has no matching albums." />}

            {data.length > 0 && <FinitePageSentinel hasNext={Boolean(result.hasNextPage)} loading={result.isFetchingNextPage} error={result.isFetchNextPageError ? result.error : null} loaded={data.length} total={meta.total} onLoadMore={fetchNext} onRetry={fetchNext} />}
        </div>
    );
}

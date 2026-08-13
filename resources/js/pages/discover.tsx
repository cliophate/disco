import { useInfiniteQuery } from '@tanstack/react-query';
import { useRef } from 'react';
import {
    DiscoverAlbumCard,
    DiscoverArtistCard,
    DiscoverEditorialCard,
    DiscoverFeatureCard,
    DiscoverOverlayCard,
    DiscoverStoryCard,
    DiscoverTextCard,
} from '../components/discover-cards';
import { MasonryFeed, MasonryFeedItem } from '../components/masonry-feed';
import { DetailTabs } from '../components/detail-tabs';
import { FinitePageSentinel } from '../components/finite-page-sentinel';
import { EmptyState, ErrorState } from '../components/states';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';
import type { DiscoverAlbumItem, DiscoverItem } from '../lib/types';

function DiscoverSkeleton() {
    return (
        <div className="discover-canvas" role="status" aria-label="Loading discovery">
            <Skeleton className="h-48 rounded-none" />
            <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4 min-[84rem]:grid-cols-5">
                <Skeleton className="h-[34rem] rounded-none sm:col-span-2" />
                <Skeleton className="h-80 rounded-none" />
                <Skeleton className="h-64 rounded-none" />
                <Skeleton className="h-96 rounded-none" />
            </div>
        </div>
    );
}

function AlbumFeedItem({ item, index }: { item: DiscoverAlbumItem; index: number }) {
    if (item.presentation === 'feature') return <DiscoverFeatureCard item={item} />;
    if (item.presentation === 'editorial') return <DiscoverEditorialCard item={item} index={index} />;
    if (item.presentation === 'overlay') return <DiscoverOverlayCard item={item} />;
    if (item.presentation === 'text') return <DiscoverTextCard item={item} />;

    return <DiscoverAlbumCard item={item} />;
}

function FeedItem({ item, index }: { item: DiscoverItem; index: number }) {
    const card = item.type === 'album'
        ? <AlbumFeedItem item={item} index={index} />
        : item.type === 'artist' ? <DiscoverArtistCard item={item} /> : <DiscoverStoryCard item={item} />;
    return (
        <MasonryFeedItem variant={item.span}>
            {card}
        </MasonryFeedItem>
    );
}

export function DiscoverPage() {
    const editionId = useRef<string | null>(null);
    const discover = useInfiniteQuery({
        queryKey: ['discover'],
        initialPageParam: { page: 1, editionId: null as string | null },
        queryFn: async ({ pageParam }) => {
            const response = await api.discover(pageParam.page, 12, pageParam.editionId ?? editionId.current);
            editionId.current ??= response.meta.edition_id;

            return response;
        },
        getNextPageParam: (lastPage) => lastPage.links.next === null
            ? undefined
            : { page: lastPage.meta.current_page + 1, editionId: lastPage.meta.edition_id },
    });
    const items = discover.data?.pages.flatMap((page) => page.data) ?? [];
    const total = discover.data?.pages[0]?.meta.total ?? 0;
    const fetchNextPage = () => discover.fetchNextPage().catch(() => undefined);

    if (discover.isLoading) return <DiscoverSkeleton />;
    if (discover.isError && !discover.data) return <ErrorState error={discover.error} retry={() => discover.refetch()} />;

    return (
        <div className="discover-canvas">
            <header className="grid gap-6 py-7 md:grid-cols-[minmax(0,0.7fr)_minmax(0,1.3fr)] md:items-end md:gap-12">
                <div>
                    <p className="editorial-eyebrow">Discover</p>
                    <h1 className="mt-3 break-words font-serif text-5xl font-bold leading-[0.92] tracking-[-0.045em] sm:text-6xl">The listening room</h1>
                </div>
                <div className="md:pb-1">
                    <p className="max-w-3xl text-sm leading-6 text-fog sm:text-base sm:leading-7">A finite edition of owned-library paths, artists recently in view, albums beyond the collection, and attributed stories from approved feeds.</p>
                </div>
            </header>
            <div className="mt-7"><DetailTabs mode="route" label="Discover views" tabs={[
                { id: 'edition', label: 'Current edition', to: '/discover' },
                { id: 'upcoming', label: 'Release window', to: '/discover/upcoming' },
            ]} /></div>

            {items.length > 0 ? (
                <section className="mt-8" aria-labelledby="discover-edition-title">
                    <h2 id="discover-edition-title" className="sr-only">Current discovery edition</h2>
                    <MasonryFeed aria-labelledby="discover-edition-title">
                        {items.map((item, index) => <FeedItem key={item.id} item={item} index={index} />)}
                    </MasonryFeed>
                </section>
            ) : discover.isError ? <div className="mt-12"><ErrorState error={discover.error} retry={() => discover.refetch()} /></div> : (
                <div className="mt-12"><EmptyState title="No discovery edition is ready" message="The feed will appear as album identities, listening facts, and recommendation evidence become available." /></div>
            )}

            {items.length > 0 && <FinitePageSentinel hasNext={Boolean(discover.hasNextPage)} loading={discover.isFetchingNextPage} error={discover.isFetchNextPageError ? discover.error : null} loaded={items.length} total={total} onLoadMore={fetchNextPage} onRetry={fetchNextPage} />}
            <footer className="mt-14 border-t border-line pt-5 text-xs leading-5 text-fog">This edition is finite and provider-free at render time. Recommendation sources remain attached to the cards they support.</footer>
        </div>
    );
}

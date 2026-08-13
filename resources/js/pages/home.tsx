import { useQuery } from '@tanstack/react-query';
import { ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import { AlbumRail } from '../components/album-rail';
import { CollectionStat } from '../components/collection-stat';
import { FeaturePanel } from '../components/feature-panel';
import { RecentCollectionActivity } from '../components/recent-collection-activity';
import { SectionHeading } from '../components/section-heading';
import { ErrorState } from '../components/states';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';
import { formatDate } from '../lib/utils';

function HomeSkeleton() {
    return <div role="status" aria-label="Loading Home"><Skeleton className="h-[32rem] rounded-none" /><div className="mt-16 grid grid-cols-1 gap-5 sm:grid-cols-3">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-32" />)}</div><div className="mt-16" aria-label="Loading recent activity"><Skeleton className="h-8 w-56" /><Skeleton className="mt-6 h-72" /></div></div>;
}

export function HomePage() {
    const home = useQuery({ queryKey: ['home'], queryFn: api.home });

    if (home.isLoading) return <HomeSkeleton />;
    if (home.isError || !home.data) return <ErrorState error={home.error} retry={() => home.refetch()} />;

    const { data, meta } = home.data;
    const homeSections = data.sections.filter((section) => !['recently-heard', 'recently-added'].includes(section.type));
    const collectionStats = [
        ['Artists', data.collection.artists],
        ['Albums', data.collection.albums],
        ['Tracks', data.collection.tracks],
    ] as const;

    return (
        <div className="section-stack">
            {data.feature ? <FeaturePanel recommendation={data.feature} /> : (
                <header className="border border-line bg-panel p-8 sm:p-12">
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-coral">Home discovery</p>
                    <h1 className="balance mt-4 max-w-4xl font-serif text-5xl font-bold leading-[0.92] tracking-[-0.045em] sm:text-7xl">{data.collection.albums > 0 ? 'Discovery is resolving your collection' : 'Discovery starts with your collection'}</h1>
                    <p className="mt-5 max-w-2xl text-sm leading-6 text-fog">{data.collection.albums > 0 ? 'Browse the available lenses below while album identities become ready to feature.' : 'Once albums are synced from Plex, this space will surface factual paths back into the music you own.'}</p>
                </header>
            )}

            <section aria-labelledby="collection-title">
                <SectionHeading id="collection-title" eyebrow="At a glance" title="Your music, accounted for" description={`A private collection view generated ${formatDate(meta.generated_at) ?? 'from the latest library data'}.`} action={<Link to="/library/albums" className="inline-flex items-center gap-2 text-sm font-bold text-cobalt outline-none hover:underline focus-visible:ring-2 focus-visible:ring-cobalt">View all albums <ArrowRight className="size-4" /></Link>} />
                <div className="grid grid-cols-1 divide-y divide-line border border-line bg-line sm:grid-cols-3 sm:gap-px sm:divide-y-0" aria-label="Collection totals">
                    {collectionStats.map(([label, value], index) => (
                        <div key={label} className="bg-panel px-6 sm:px-8">
                            <CollectionStat label={`0${index + 1} / ${label}`} value={value} />
                        </div>
                    ))}
                </div>
            </section>

            <RecentCollectionActivity events={data.activity} meta={meta.activity} refreshing={home.isFetching && !home.isLoading} />

            {homeSections.map((section) => <AlbumRail key={section.type} section={section} />)}

            <footer className="border-t border-line py-5 text-xs text-fog">
                <p>Owned-library lenses use Plex and ListenBrainz facts. Beyond-library suggestions are explicitly attributed to ListenBrainz.</p>
            </footer>
        </div>
    );
}

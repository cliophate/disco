import { ArrowUpRight, CalendarPlus, Headphones } from 'lucide-react';
import { Link } from 'react-router-dom';
import type { CollectionActivityEvent, HomeMeta } from '../lib/types';
import { formatDate } from '../lib/utils';
import { ActivityRail } from './activity-rail';
import { AlbumListControl } from './album-list-control';
import { Artwork } from './artwork';
import { SectionHeading } from './section-heading';

function ActivityCard({ event }: { event: CollectionActivityEvent }) {
    const artist = event.album.artist;
    const Icon = event.kind === 'played' ? Headphones : CalendarPlus;
    const label = event.kind === 'played' ? 'Played' : 'Added';

    return (
        <article className="group relative flex h-full w-[min(17rem,78vw)] flex-col border border-line bg-panel p-3 sm:w-72">
            <Link to={`/albums/${event.album.id}`} aria-label={event.album.title} className="block outline-none focus-visible:ring-2 focus-visible:ring-cobalt">
                <div className="relative overflow-hidden">
                    <Artwork artwork={event.album.artwork} title={event.album.title} artist={artist?.name} className="shadow-none" imageClassName="transition duration-500 motion-reduce:transition-none group-hover:scale-[1.02] motion-reduce:group-hover:scale-100" />
                    <span className="absolute right-3 top-3 grid size-8 translate-y-1 place-items-center rounded-full bg-panel text-ink opacity-0 shadow-sm transition group-hover:translate-y-0 group-hover:opacity-100 group-focus-within:translate-y-0 group-focus-within:opacity-100"><ArrowUpRight className="size-4" aria-hidden="true" /></span>
                </div>
                <div className="px-1 pb-1 pt-4">
                    <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-coral"><Icon className="size-4" aria-hidden="true" />{label}</p>
                    <h3 className="mt-2 font-serif text-xl font-bold leading-tight transition-colors group-hover:text-cobalt">{event.album.title}</h3>
                </div>
            </Link>
            <div className="mt-auto px-1 pb-1 pt-4">
                {artist?.id ? (
                    <Link to={`/artists/${artist.id}`} className="inline-block text-sm text-fog outline-none hover:text-cobalt hover:underline focus-visible:ring-2 focus-visible:ring-cobalt">
                        {artist.name}
                    </Link>
                ) : artist ? <p className="text-sm text-fog">{artist.name}</p> : null}
                <p className="mt-3 text-xs text-fog">
                    {label} <time dateTime={event.occurred_at}>{formatDate(event.occurred_at, { dateStyle: 'medium', timeStyle: 'short' })}</time>
                </p>
            </div>
            <div className="absolute left-5 top-5 z-10 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"><AlbumListControl albumId={event.album.id} initialState={event.album.list_state ?? null} iconOnly /></div>
        </article>
    );
}

export function RecentCollectionActivity({ events, meta, refreshing = false }: { events: CollectionActivityEvent[]; meta: HomeMeta['activity']; refreshing?: boolean }) {
    return (
        <section aria-labelledby="recent-activity-title">
            <SectionHeading
                id="recent-activity-title"
                eyebrow="Collection activity"
                title="Recent activity"
                description="Played means the latest matched listening event for an album you own. Added means the date your synchronized library reports."
            />
            {meta.stale && (
                <p role="status" className="mb-4 border-l-4 border-coral bg-panel px-4 py-3 text-sm text-fog">
                    Activity may be out of date. {meta.added_as_of ? `Library additions are current as of ${formatDate(meta.added_as_of)}.` : 'No successful library sync is available.'}{meta.played_as_of ? ` Listening is current as of ${formatDate(meta.played_as_of)}.` : ''}
                </p>
            )}
            {refreshing && <p role="status" className="mb-4 text-sm text-fog">Refreshing activity in the background.</p>}
            <ActivityRail label="Recent collection activity" landmark={false} empty="No matched plays or synchronized addition dates are available yet.">
                {events.map((event) => <ActivityCard key={event.id} event={event} />)}
            </ActivityRail>
        </section>
    );
}

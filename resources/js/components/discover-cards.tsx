import { ArrowUpRight } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import type { Album, DiscoverAlbumItem, DiscoverArtistItem, DiscoverEditorialItem } from '../lib/types';
import { AlbumPlexAction } from './album-plex-action';
import { ArtistPortrait } from './artist-portrait';
import { Artwork } from './artwork';

const linkClass = 'group block overflow-hidden border border-line bg-panel text-ink outline-none transition-colors hover:border-cobalt focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-4 focus-visible:ring-offset-paper';

function DiscoverPlexAction({ album, dark = false, contained = false }: { album: Album; dark?: boolean; contained?: boolean }) {
    const available = album.owned && album.open_in_plex_status !== 'unavailable'
        && (album.open_in_plex_status === 'choice-required' || album.plex_item_id !== null);
    if (!available) return null;

    return <div className={dark ? 'border-t border-white/20 bg-cobalt-deep px-7 pb-7 pt-5 sm:px-9 sm:pb-9 sm:pt-6' : contained ? 'border-t border-line bg-panel px-5 pb-5 pt-5' : 'border border-line bg-panel px-5 pb-5 pt-5'}><AlbumPlexAction album={album} /></div>;
}

export function DiscoverFeatureCard({ item }: { item: DiscoverAlbumItem }) {
    const { album, reasons } = item.recommendation;

    return (
        <article>
        <Link to={`/albums/${album.id}`} className="group block overflow-hidden bg-cobalt-deep text-cream outline-none focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-4 focus-visible:ring-offset-paper">
            <Artwork artwork={album.artwork} title={album.title} artist={album.artist?.name} priority className="aspect-[16/10] shadow-none" imageClassName="transition duration-500 group-hover:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover:scale-100" />
            <div className="relative p-7 sm:p-9">
                <span className="absolute right-6 top-6 grid size-11 place-items-center rounded-full border border-white/25" aria-hidden="true"><ArrowUpRight className="size-5" /></span>
                <p className="pr-14 text-xs font-bold uppercase tracking-[0.22em] text-white/70">{item.lens}</p>
                <h3 className="mt-5 break-words [overflow-wrap:anywhere] font-serif text-5xl font-bold leading-[0.9] tracking-[-0.05em] sm:text-6xl">{album.title}</h3>
                <p className="mt-4 text-base text-white/75">{album.artist?.name ?? 'Unknown artist'}{album.year ? ` · ${album.year}` : ''}</p>
                {reasons[0] && <p className="mt-7 max-w-2xl text-sm leading-6 text-white/75">{reasons[0].text}</p>}
            </div>
        </Link>
        <DiscoverPlexAction album={album} dark />
        </article>
    );
}

export function DiscoverAlbumCard({ item }: { item: DiscoverAlbumItem }) {
    const { album } = item.recommendation;

    return (
        <article>
        <Link to={`/albums/${album.id}`} className={linkClass}>
            <Artwork artwork={album.artwork} title={album.title} artist={album.artist?.name} className="shadow-none" imageClassName="transition duration-500 group-hover:scale-[1.025] motion-reduce:transition-none motion-reduce:group-hover:scale-100" />
            <div className="p-5">
                <p className="text-xs font-bold uppercase tracking-[0.18em] text-coral">{item.lens}</p>
                <h3 className="mt-3 break-words [overflow-wrap:anywhere] font-serif text-2xl font-bold leading-[1.02] group-hover:text-cobalt">{album.title}</h3>
                <p className="mt-2 text-sm text-fog">{album.artist?.name ?? 'Unknown artist'}{album.year ? ` · ${album.year}` : ''}</p>
            </div>
        </Link>
        </article>
    );
}

export function DiscoverEditorialCard({ item, index }: { item: DiscoverAlbumItem; index: number }) {
    const { album, reasons } = item.recommendation;
    const mediaRatio = index % 2 === 0 ? 'aspect-[4/5]' : 'aspect-[3/2]';

    return (
        <article>
        <Link to={`/albums/${album.id}`} className={linkClass}>
            <Artwork artwork={album.artwork} title={album.title} artist={album.artist?.name} className={`${mediaRatio} shadow-none`} />
            <div className="bg-raised p-6">
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-coral">{item.lens}</p>
                <h3 className="mt-4 break-words [overflow-wrap:anywhere] font-serif text-3xl font-bold leading-none group-hover:text-cobalt">{album.title}</h3>
                <p className="mt-2 text-sm text-fog">{album.artist?.name ?? 'Unknown artist'}</p>
                <p className="mt-5 text-sm leading-6 text-fog">{reasons[0]?.text ?? item.description}</p>
            </div>
        </Link>
        <DiscoverPlexAction album={album} />
        </article>
    );
}

export function DiscoverTextCard({ item }: { item: DiscoverAlbumItem }) {
    const { album, reasons } = item.recommendation;

    return (
        <article>
        <Link to={`/albums/${album.id}`} className="group block min-h-72 border border-cobalt/15 bg-cobalt/5 p-7 text-ink outline-none hover:border-cobalt focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-4 focus-visible:ring-offset-paper sm:p-8">
            <p className="text-xs font-bold uppercase tracking-[0.22em] text-coral">{item.lens}</p>
            <h3 className="mt-7 break-words [overflow-wrap:anywhere] font-serif text-4xl font-bold leading-[0.95] tracking-[-0.035em] group-hover:text-cobalt">{album.title}</h3>
            <p className="mt-3 text-sm text-fog">{album.artist?.name ?? 'Unknown artist'}</p>
            <p className="mt-8 text-sm leading-6 text-fog">{reasons[0]?.text ?? item.description}</p>
        </Link>
        <DiscoverPlexAction album={album} />
        </article>
    );
}

export function DiscoverOverlayCard({ item }: { item: DiscoverAlbumItem }) {
    const { album } = item.recommendation;
    const category = album.genres[0] ?? item.lens;

    return (
        <article className="border border-line">
        <Link to={`/albums/${album.id}`} className="group relative block overflow-hidden bg-[#17191f] text-[#fff8e9] outline-none focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-4 focus-visible:ring-offset-paper">
            <Artwork artwork={album.artwork} title={album.title} artist={album.artist?.name} className="aspect-[3/4] shadow-none" imageClassName="transition duration-500 group-hover:scale-[1.025] motion-reduce:transition-none motion-reduce:group-hover:scale-100" />
            <span className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/15 to-transparent" aria-hidden="true" />
            <span className="absolute inset-x-0 bottom-0 block p-6">
                <span className="text-xs font-bold uppercase tracking-[0.2em] text-white/75">{category}</span>
                <span className="mt-3 block break-words [overflow-wrap:anywhere] font-serif text-3xl font-bold leading-none">{album.title}</span>
                <span className="mt-2 block text-sm text-white/75">{album.artist?.name ?? 'Unknown artist'}</span>
            </span>
        </Link>
        <DiscoverPlexAction album={album} contained />
        </article>
    );
}

export function DiscoverArtistCard({ item }: { item: DiscoverArtistItem }) {
    return (
        <Link to={`/artists/${item.artist.id}`} className={linkClass}>
            <div className="p-6">
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-coral">{item.lens}</p>
                <ArtistPortrait portrait={item.artist.portrait} name={item.artist.name} className="mx-auto mt-5 w-36 sm:w-40" />
                <h3 className="mt-5 break-words [overflow-wrap:anywhere] text-center font-serif text-3xl font-bold leading-none group-hover:text-cobalt">{item.artist.name}</h3>
                {(item.artist.type || item.artist.area) && <p className="mt-3 text-center text-sm text-fog">{[item.artist.type, item.artist.area].filter(Boolean).join(' · ')}</p>}
            </div>
        </Link>
    );
}

export function DiscoverStoryCard({ item }: { item: DiscoverEditorialItem }) {
    const story = item.editorial;
    const [imageFailed, setImageFailed] = useState(false);
    const date = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(story.published_at));

    return (
        <a href={story.url} target="_blank" rel="noreferrer" className="group grid overflow-hidden border border-line bg-[#17191f] text-[#fff8e9] outline-none focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-4 focus-visible:ring-offset-paper sm:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
            <div className="relative aspect-[16/10] overflow-hidden bg-cobalt-deep sm:aspect-auto sm:min-h-80">
                <div className="absolute inset-0 grid content-end bg-gradient-to-br from-cobalt-deep to-coral-deep p-6"><span className="font-serif text-4xl font-bold italic tracking-[-0.04em] text-white/85">Pitchfork</span></div>
                {story.image && !imageFailed && <img src={story.image.url} width={story.image.width ?? undefined} height={story.image.height ?? undefined} alt="" loading="lazy" decoding="async" referrerPolicy="no-referrer" onError={() => setImageFailed(true)} className="absolute inset-0 size-full object-cover transition duration-500 group-hover:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover:scale-100" />}
            </div>
            <div className="relative flex min-w-0 flex-col justify-between p-7 sm:p-9">
                <ArrowUpRight className="absolute right-7 top-7 size-5 text-white/65" aria-hidden="true" />
                <div>
                    <p className="pr-10 text-xs font-bold uppercase tracking-[0.2em] text-coral">{story.publication}{story.category ? ` · ${story.category}` : ''}</p>
                    <h3 className="mt-5 break-words [overflow-wrap:anywhere] font-serif text-4xl font-bold leading-[0.95] tracking-[-0.035em] group-hover:text-coral sm:text-5xl">{story.headline}</h3>
                    {story.excerpt && <p className="mt-6 line-clamp-4 text-sm leading-6 text-white/70">{story.excerpt}</p>}
                </div>
                <p className="mt-8 text-xs text-white/60">{story.author ? `By ${story.author} · ` : ''}{date} · {story.publisher}</p>
            </div>
        </a>
    );
}

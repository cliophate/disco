import { ArrowUpRight } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { Link } from 'react-router-dom';
import type { ArtworkImage } from '../lib/types';
import { cn, requiresTextContainment } from '../lib/utils';
import { Artwork } from './artwork';

export type CoverCardVariant = 'grid' | 'rail' | 'compact';

export interface CoverCardProps {
    to: string;
    title: string;
    artwork: ArtworkImage | null;
    artist?: string | null;
    artistTo?: string;
    artistSuffix?: string | null;
    date?: ReactNode;
    action?: ReactNode;
    overlayAction?: ReactNode;
    details?: ReactNode;
    collectionState?: 'owned' | 'outside';
    index?: number;
    variant?: CoverCardVariant;
    state?: unknown;
}

export function CoverCard({ to, title, artwork, artist, artistTo, artistSuffix, date, action, overlayAction, details, collectionState = 'owned', index = 0, variant = 'grid', state }: CoverCardProps) {
    const artistLabel = `${artist ?? ''}${artistSuffix ? ` · ${artistSuffix}` : ''}`;
    const boundedTitle = requiresTextContainment(title);
    const visibleTitle = boundedTitle ? 'Symbolic title' : title;
    const linkLabel = boundedTitle ? `Album with a symbolic title${artist ? ` by ${artist}` : ''}` : title;
    const delay = { animationDelay: `${Math.min(index * 35, 245)}ms` } as CSSProperties;

    if (variant !== 'compact') {
        return (
            <article className={cn('group relative flex h-full min-w-0 flex-col border border-line bg-panel p-3 reveal', variant === 'rail' && 'w-[min(17rem,78vw)] shrink-0 sm:w-72')} style={delay}>
                <Link to={to} state={state} aria-label={linkLabel} className="focus-ring block min-w-0 cursor-pointer">
                    <div className="relative overflow-hidden">
                        <Artwork artwork={artwork} title={title} artist={artistLabel} imageClassName="transition duration-500 motion-reduce:transition-none group-hover:scale-[1.02] motion-reduce:group-hover:scale-100" />
                        {collectionState === 'outside' && <span className="absolute bottom-3 left-3 bg-ink px-2 py-1 text-[9px] font-bold uppercase tracking-[0.14em] text-paper">Beyond</span>}
                        <span className="absolute right-3 top-3 grid size-8 translate-y-1 place-items-center rounded-full bg-panel text-ink opacity-0 shadow-sm transition group-hover:translate-y-0 group-hover:opacity-100 group-focus-within:translate-y-0 group-focus-within:opacity-100">
                            <ArrowUpRight className="size-4" aria-hidden="true" />
                        </span>
                    </div>
                    <div className="min-w-0 px-1 pb-1 pt-3">
                        <h3 title={boundedTitle ? title : undefined} className="max-w-full break-words [overflow-wrap:anywhere] font-serif text-xl font-bold leading-[1.05] text-ink transition-colors group-hover:text-cobalt">{visibleTitle}</h3>
                    </div>
                </Link>
                <div className="mt-auto min-w-0 px-1 pb-1 pt-4">
                    {artist && <p className="break-words [overflow-wrap:anywhere] text-sm text-fog">{artistTo ? <Link to={artistTo} className="outline-none hover:text-cobalt hover:underline focus-visible:ring-2 focus-visible:ring-cobalt">{artist}</Link> : artist}{artistSuffix ? ` · ${artistSuffix}` : ''}</p>}
                    {details}
                    {date && <p className="mt-3 text-xs text-fog">{date}</p>}
                </div>
                {overlayAction && <div className="absolute left-5 top-5 z-10 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">{overlayAction}</div>}
                {action && <div className="-mx-3 -mb-3 mt-4 border-t border-line px-4 py-4">{action}</div>}
            </article>
        );
    }

    return (
        <article
            className={cn(
                'group relative min-w-0 reveal',
                'flex min-w-0 items-center gap-4',
            )}
            style={delay}
        >
            <Link
                to={to}
                state={state}
                className={cn(
                    'focus-ring block min-w-0 cursor-pointer',
                    'grid flex-1 grid-cols-[6rem_minmax(0,1fr)] items-center gap-4 sm:grid-cols-[7rem_minmax(0,1fr)]',
                )}
            >
                <div className="relative overflow-hidden">
                    <Artwork
                        artwork={artwork}
                        title={title}
                        artist={artistLabel}
                        className="w-24 sm:w-28"
                        imageClassName="transition duration-500 motion-reduce:transition-none group-hover:scale-[1.02] motion-reduce:group-hover:scale-100"
                    />
                    {collectionState === 'outside' && <span className="absolute bottom-3 left-3 bg-ink px-2 py-1 text-[9px] font-bold uppercase tracking-[0.14em] text-paper">Beyond</span>}
                    <span className="absolute right-3 top-3 grid size-8 translate-y-1 place-items-center rounded-full bg-panel text-ink opacity-0 shadow-sm transition group-hover:translate-y-0 group-hover:opacity-100 group-focus-within:translate-y-0 group-focus-within:opacity-100">
                        <ArrowUpRight className="size-4" aria-hidden="true" />
                    </span>
                </div>
                <div className="min-w-0">
                    {date && <p className="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-coral">{date}</p>}
                    <h3 title={boundedTitle ? title : undefined} className="max-w-full break-words [overflow-wrap:anywhere] font-serif text-xl font-bold leading-[1.05] text-ink transition-colors group-hover:text-cobalt">{visibleTitle}</h3>
                    {artist && <p className="mt-1 break-words [overflow-wrap:anywhere] text-sm text-fog">{artistLabel}</p>}
                    {details}
                </div>
            </Link>
            {action && <div className="mt-0 shrink-0">{action}</div>}
        </article>
    );
}

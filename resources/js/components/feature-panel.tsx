import { ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import type { Recommendation } from '../lib/types';
import { Artwork } from './artwork';

export function FeaturePanel({ recommendation, headingLevel = 'h1' }: { recommendation: Recommendation; headingLevel?: 'h1' | 'h3' }) {
    const { album, reasons } = recommendation;
    const Heading = headingLevel;

    return (
        <article className="relative overflow-hidden bg-cobalt-deep text-cream">
            <div className="absolute -right-28 -top-32 size-80 rounded-full border border-white/15" aria-hidden="true" />
            <div className="grid min-h-[34rem] lg:grid-cols-[1.08fr_0.92fr]">
                <div className="relative min-w-0 flex flex-col justify-between p-7 sm:p-10 lg:p-12">
                    <div className="flex items-center justify-between gap-4">
                        <p className="text-xs font-bold uppercase tracking-[0.22em] text-white/75">{recommendation.lens ?? 'A factual path through your library'}</p>
                    </div>
                    <div className="my-12 max-w-2xl">
                        <p className="text-xs font-bold uppercase tracking-[0.22em] text-white/70">Listen next</p>
                        <Heading className="balance mt-4 break-words font-serif text-6xl font-bold leading-[0.86] tracking-[-0.055em] sm:text-8xl">{album.title}</Heading>
                        <p className="mt-5 text-lg text-white/80">{album.artist?.name ?? 'Unknown artist'}{album.first_release_date ? ` · ${album.first_release_date.year}` : album.year ? ` · ${album.year}` : ''}</p>
                        {reasons[0] && <p className="mt-7 max-w-xl text-sm leading-6 text-white/75">{reasons[0].text}</p>}
                    </div>
                    <Link to={`/albums/${album.id}`} className="inline-flex min-h-11 w-fit items-center gap-2 text-sm font-bold outline-none hover:underline focus-visible:ring-2 focus-visible:ring-white">Explore the album <ArrowRight className="size-4" /></Link>
                </div>
                <Link to={`/albums/${album.id}`} className="relative m-5 mt-0 self-center outline-none focus-visible:ring-2 focus-visible:ring-white lg:m-10 lg:ml-0" aria-label={`View ${album.title}`}>
                    <Artwork artwork={album.artwork} title={album.title} artist={album.artist?.name} priority className="mx-auto w-full max-w-[34rem] shadow-[0_25px_80px_rgb(0_0_0/0.35)]" />
                </Link>
            </div>
        </article>
    );
}

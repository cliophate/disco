import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import type { ArtworkImage } from '../lib/types';
import { ArtistPortrait } from './artist-portrait';

export interface EntityPortraitLinkProps {
    to: string;
    name: string;
    portrait: ArtworkImage | null;
    detail?: ReactNode;
    state?: unknown;
}

export function EntityPortraitLink({ to, name, portrait, detail, state }: EntityPortraitLinkProps) {
    return (
        <Link to={to} state={state} className="group flex min-w-0 cursor-pointer items-center gap-5 border-t border-line px-2 py-5 text-ink outline-none transition-colors hover:border-ink/35 hover:bg-raised hover:text-cobalt focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cobalt">
            <ArtistPortrait portrait={portrait} name={name} className="w-20 shrink-0 transition-transform group-hover:scale-[1.02]" />
            <span className="min-w-0 flex-1">
                <span className="block break-words [overflow-wrap:anywhere] font-serif text-xl font-bold leading-tight">{name}</span>
                {detail && <span className="mt-1 block break-words [overflow-wrap:anywhere] text-xs text-fog">{detail}</span>}
            </span>
        </Link>
    );
}

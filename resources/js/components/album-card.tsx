import type { Album, RecommendationReason } from '../lib/types';
import type { ReactNode } from 'react';
import { formatDate } from '../lib/utils';
import { CoverCard } from './cover-card';
import { AlbumListControl } from './album-list-control';

export type AlbumCardVariant = 'grid' | 'rail' | 'compact';

export function RecommendationReasons({ reasons }: { reasons: RecommendationReason[] }) {
    if (!reasons.length) return null;

    return (
        <div className="mt-3 space-y-2" aria-label="Why this was recommended">
            {reasons.map((reason) => (
                <p key={`${reason.code}-${reason.text}`} className="line-clamp-3 text-xs leading-5 text-fog">{reason.text}</p>
            ))}
        </div>
    );
}

export function AlbumCard({ album, index = 0, variant = 'grid', reasons = [], actions, metadata, state }: { album: Album; index?: number; variant?: AlbumCardVariant; reasons?: RecommendationReason[]; actions?: ReactNode; metadata?: ReactNode; state?: unknown }) {
    const overlayListControl = variant === 'compact' ? undefined : <AlbumListControl albumId={album.id} initialState={album.list_state ?? null} iconOnly />;

    return (
        <CoverCard
            to={`/albums/${album.id}`}
            title={album.title}
            artwork={album.artwork}
            artist={album.artist?.name ?? 'Unknown artist'}
            artistTo={album.artist?.id ? `/artists/${album.artist.id}` : undefined}
            artistSuffix={album.year ? String(album.year) : null}
            date={variant !== 'compact' && album.added_at ? `Added ${formatDate(album.added_at)}` : undefined}
            collectionState={album.owned ? 'owned' : 'outside'}
            index={index}
            variant={variant}
            action={variant === 'compact' ? <div className="flex flex-wrap items-center gap-2"><AlbumListControl albumId={album.id} initialState={album.list_state ?? null} />{actions}</div> : actions}
            overlayAction={overlayListControl}
            state={state}
            details={(
                <>
                    {album.release_type && variant !== 'rail' && <p className="mt-2 text-xs font-bold uppercase tracking-[0.16em] text-fog">{album.release_type}</p>}
                    <RecommendationReasons reasons={reasons} />
                    {metadata}
                </>
            )}
        />
    );
}

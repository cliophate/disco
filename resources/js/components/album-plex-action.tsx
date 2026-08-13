import { ListMusic } from 'lucide-react';
import { Link } from 'react-router-dom';
import type { Album } from '../lib/types';
import { OpenInPlexButton } from './open-in-plex-button';
import { Button } from './ui/button';

export function AlbumPlexAction({ album, state }: { album: Album; state?: { from: string; label: string } }) {
    if (!album.owned || album.open_in_plex_status === 'unavailable') return null;
    if (album.open_in_plex_status === 'choice-required') {
        return <Button asChild variant="secondary" size="sm"><Link to={`/albums/${album.id}`} state={state}><ListMusic className="size-4" />Choose Plex copy</Link></Button>;
    }
    if (!album.plex_item_id) return null;

    return <OpenInPlexButton plexItemId={album.plex_item_id} compact />;
}

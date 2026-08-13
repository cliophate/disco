import { CirclePause, CirclePlay, Clock3, Radio, ServerOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { PlexPlaybackContext as Context } from '../lib/types';

export function PlexPlaybackContext({ context }: { context: Context }) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (context.status !== 'currently_active' || !context.expires_at) return;
        const delay = Math.max(0, new Date(context.expires_at).getTime() - Date.now()) + 50;
        const timer = window.setTimeout(() => setNow(Date.now()), delay);
        return () => window.clearTimeout(timer);
    }, [context.expires_at, context.status]);
    const status = context.status === 'currently_active' && context.expires_at && new Date(context.expires_at).getTime() <= now ? 'available' : context.status;
    const presentation = status === 'currently_active'
        ? context.player_state === 'paused'
            ? { label: 'Paused in Plex', detail: 'Observed recently on the configured Plex server.', Icon: CirclePause }
            : context.player_state === 'buffering'
                ? { label: 'Buffering in Plex', detail: 'Observed recently on the configured Plex server.', Icon: Radio }
                : { label: 'Playing in Plex', detail: 'Observed recently on the configured Plex server.', Icon: CirclePlay }
        : status === 'recently_played'
            ? { label: 'Recently played in Plex', detail: context.last_played_at ? `Plex recorded activity ${new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(context.last_played_at))}.` : 'Plex recorded recent activity.', Icon: Clock3 }
            : status === 'available'
                ? { label: 'Available in Plex', detail: 'Present in the last successful library sync.', Icon: Radio }
                : { label: 'Unavailable in Plex', detail: 'No active copy is present in the configured library.', Icon: ServerOff };

    return <div className="flex max-w-md items-start gap-3 border-l-2 border-cobalt pl-3 text-sm" aria-label="Plex playback context"><presentation.Icon className="mt-0.5 size-4 shrink-0 text-cobalt" /><div><p className="font-semibold">{presentation.label}</p><p className="mt-0.5 text-xs leading-5 text-fog">{presentation.detail}</p></div></div>;
}

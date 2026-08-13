import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import type { ArtistDetail } from '../lib/types';
import { Button } from './ui/button';

export function ArtistFollowButton({ artistId, state, dark = false, secondary = false }: { artistId: string; state: ArtistDetail['follow_state']; dark?: boolean; secondary?: boolean }) {
    const queryClient = useQueryClient();
    const [explicit, setExplicit] = useState(state.explicit);
    useEffect(() => setExplicit(state.explicit), [state.explicit]);
    const mutation = useMutation({
        mutationFn: async () => {
            if (explicit) {
                await api.unfollowArtist(artistId);
                return null;
            }

            return api.followArtist(artistId);
        },
        onSuccess: async () => {
            setExplicit((current) => !current);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['artist', artistId] }),
                queryClient.invalidateQueries({ queryKey: ['home'] }),
                queryClient.invalidateQueries({ queryKey: ['discover'] }),
                queryClient.invalidateQueries({ queryKey: ['beyond'] }),
            ]);
        },
    });

    return (
        <div>
            <Button variant={explicit || secondary ? 'secondary' : 'default'} onClick={() => mutation.mutate()} disabled={mutation.isPending} className={dark && (explicit || secondary) ? 'border-white/40 bg-black/25 text-white hover:border-white' : undefined}>
                {mutation.isPending ? 'Saving...' : explicit ? 'Unfollow artist' : 'Follow artist'}
            </Button>
            {state.implicit && <p className={`mt-2 text-xs ${dark ? 'text-white/75' : 'text-fog'}`}>Personalization seed from your Plex library.</p>}
            {mutation.isError && <p className={`mt-2 text-xs ${dark ? 'text-white' : 'text-coral-deep'}`} role="alert">Follow state could not be saved.</p>}
        </div>
    );
}

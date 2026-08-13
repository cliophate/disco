import { useMutation } from '@tanstack/react-query';
import { ExternalLink } from 'lucide-react';
import { api } from '../lib/api';
import { Button } from './ui/button';

export function OpenInPlexButton({ plexItemId, label = 'Open in Plex', compact = false, primary = false }: { plexItemId: string; label?: string; compact?: boolean; primary?: boolean }) {
    const openPlex = useMutation({
        mutationFn: () => api.plexTarget(plexItemId),
        onSuccess: ({ url }) => window.location.assign(url),
    });

    return (
        <div>
            <Button variant={primary ? 'default' : 'secondary'} size={compact ? 'sm' : 'default'} onClick={() => openPlex.mutate()} disabled={openPlex.isPending}>
                {openPlex.isPending ? 'Finding in Plex…' : label}<ExternalLink className="size-4" />
            </Button>
            {openPlex.isError && <p className="mt-2 text-sm text-coral" role="alert">{openPlex.error.message}</p>}
        </div>
    );
}

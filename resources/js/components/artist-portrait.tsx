import { UserRound } from 'lucide-react';
import { useState } from 'react';
import type { ArtworkImage } from '../lib/types';
import { cn } from '../lib/utils';

export function ArtistPortrait({ portrait, name, className, variant = 'round', priority = false }: { portrait: ArtworkImage | null; name: string; className?: string; variant?: 'round' | 'hero'; priority?: boolean }) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const showImage = portrait !== null && portrait.url !== failedUrl;

    return (
        <div className={cn('relative overflow-hidden bg-raised', variant === 'round' ? 'aspect-square rounded-full ring-1 ring-line' : portrait && portrait.width !== null && portrait.height !== null ? portrait.height > portrait.width ? 'aspect-[4/5]' : portrait.width > portrait.height ? 'aspect-[5/3]' : 'aspect-square' : 'aspect-square', className)}>
            {showImage ? (
                <img
                    src={portrait.url}
                    alt={`${name} portrait`}
                    width={portrait.width ?? undefined}
                    height={portrait.height ?? undefined}
                    loading={priority ? 'eager' : 'lazy'}
                    decoding="async"
                    fetchPriority={priority ? 'high' : undefined}
                    onError={() => setFailedUrl(portrait.url)}
                    className="size-full object-cover"
                />
            ) : (
                <div className="grid size-full place-items-center bg-gradient-to-br from-coral/70 to-cobalt-deep text-cream" role="img" aria-label={`${name} portrait unavailable`}>
                    <UserRound className="size-1/3" strokeWidth={1.2} aria-hidden="true" />
                </div>
            )}
        </div>
    );
}

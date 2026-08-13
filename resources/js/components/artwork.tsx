import { Disc3 } from 'lucide-react';
import { useState } from 'react';
import type { ArtworkImage } from '../lib/types';
import { cn } from '../lib/utils';

const palettes = [
    ['#2146c7', '#9eb1ef'],
    ['#dc5b4d', '#efb09d'],
    ['#be8a35', '#ead29b'],
    ['#34766c', '#a8d0c1'],
    ['#6c4f98', '#c2afd7'],
] as const;

function hash(value: string) {
    return [...value].reduce((total, character) => ((total * 31) + character.charCodeAt(0)) >>> 0, 7);
}

interface ArtworkProps {
    artwork: ArtworkImage | null;
    title: string;
    artist?: string | null;
    className?: string;
    imageClassName?: string;
    priority?: boolean;
}

export function Artwork({ artwork, title, artist, className, imageClassName, priority = false }: ArtworkProps) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const palette = palettes[hash(title) % palettes.length];
    const showImage = artwork !== null && artwork.url !== failedUrl;
    const label = `${title}${artist ? ` by ${artist}` : ''} artwork`;

    return (
        <div className={cn('mobile-scroll-artwork relative aspect-square overflow-hidden bg-raised shadow-[0_16px_40px_rgb(25_32_51/0.14)]', className)}>
            {!showImage && (
                <div
                    className="absolute inset-0 overflow-hidden"
                    data-testid="artwork-fallback"
                    role="img"
                    aria-label={`${label} unavailable`}
                    style={{ background: `linear-gradient(145deg, ${palette[1]}, ${palette[0]})` }}
                >
                    <div className="absolute inset-[8%] border border-white/35" />
                    <Disc3 className="absolute -bottom-[14%] -right-[12%] size-[78%] text-white/20" strokeWidth={0.8} aria-hidden="true" />
                    <p className="absolute inset-x-[10%] bottom-[10%] line-clamp-2 [overflow-wrap:anywhere] font-serif text-xl font-bold leading-none text-white sm:text-2xl">{title}</p>
                </div>
            )}
            {showImage && (
                <img
                    src={artwork.url}
                    alt={label}
                    width={artwork.width ?? undefined}
                    height={artwork.height ?? undefined}
                    loading={priority ? 'eager' : 'lazy'}
                    fetchPriority={priority ? 'high' : 'auto'}
                    decoding="async"
                    onError={() => setFailedUrl(artwork.url)}
                    className={cn('absolute inset-0 size-full object-cover', imageClassName)}
                />
            )}
        </div>
    );
}

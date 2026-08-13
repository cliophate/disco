import { ExternalLink } from 'lucide-react';

export function DiscogsAttribution({ sourceUrl }: { sourceUrl: string }) {
    return (
        <div className="mt-5 border-t border-line pt-4 text-xs leading-5 text-fog">
            <a href={sourceUrl} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center gap-1.5 font-semibold text-cobalt hover:underline">
                Data provided by Discogs. <ExternalLink className="size-3" />
            </a>
            <p>This application uses Discogs' API but is not affiliated with, sponsored or endorsed by Discogs. "Discogs" is a trademark of Zink Media, LLC.</p>
        </div>
    );
}

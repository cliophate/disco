import type { NarrativeDescription } from '../lib/types';
import { cn } from '../lib/utils';

export function AttributedNarrative({ description, eyebrow, title, titleId, className }: {
    description: NarrativeDescription | null;
    eyebrow: string;
    title: string;
    titleId: string;
    className?: string;
}) {
    if (!description) return null;

    return (
        <section className={cn('max-w-3xl', className)} aria-labelledby={titleId}>
            <p className="editorial-eyebrow">{eyebrow}</p>
            <h2 id={titleId} className="mt-2 font-serif text-3xl font-bold">{title}</h2>
            <p className="mt-4 whitespace-pre-line text-base leading-7 text-ink/85">{description.text}</p>
            <p className="mt-4 text-xs text-fog">
                Source: <a href={description.source_url} target="_blank" rel="noreferrer" className="font-semibold text-cobalt hover:underline">{description.provider_name}</a>
                {description.license_name && <> · <a href={description.license_url ?? description.source_url} target="_blank" rel="noreferrer" className="hover:underline">{description.license_name}</a></>}
                {description.language !== 'en' && <> · {description.language.toUpperCase()}</>}
            </p>
        </section>
    );
}

import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';

interface EditorialTileBaseProps {
    to: string;
    title: string;
    eyebrow?: ReactNode;
    media?: ReactNode;
}

interface EditorialTileFactProps extends EditorialTileBaseProps {
    fact: ReactNode;
    excerpt?: never;
    attribution?: never;
}

interface EditorialTileExcerptProps extends EditorialTileBaseProps {
    excerpt: ReactNode;
    attribution?: { label: string; href?: string };
    fact?: never;
}

export type EditorialTileProps = EditorialTileFactProps | EditorialTileExcerptProps;

export function EditorialTile(props: EditorialTileProps) {
    return (
        <article className="grid min-w-0 gap-5 border-t border-line bg-panel py-5 transition-colors hover:border-ink/35 hover:bg-raised sm:grid-cols-[minmax(0,1fr)_8rem] sm:items-start">
            <div className="min-w-0">
                {props.eyebrow && <div className="editorial-eyebrow mb-3">{props.eyebrow}</div>}
                <h3 className="font-serif text-2xl font-bold leading-tight">
                    <Link to={props.to} className="break-words [overflow-wrap:anywhere] text-ink outline-none hover:text-cobalt focus-visible:ring-2 focus-visible:ring-cobalt">{props.title}</Link>
                </h3>
                {'fact' in props ? (
                    <div className="mt-4 text-base font-semibold text-fog">{props.fact}</div>
                ) : (
                    <>
                        <div className="mt-4 max-w-prose text-base leading-7 text-fog">{props.excerpt}</div>
                        {props.attribution && (
                            <p className="mt-3 text-xs text-fog">
                                Source:{' '}
                                {props.attribution.href
                                    ? <a href={props.attribution.href} target="_blank" rel="noreferrer" className="font-semibold text-cobalt outline-none hover:underline focus-visible:ring-2 focus-visible:ring-cobalt">{props.attribution.label}</a>
                                    : <span className="font-semibold">{props.attribution.label}</span>}
                            </p>
                        )}
                    </>
                )}
            </div>
            {props.media && <div className="row-first w-24 sm:col-start-2 sm:row-auto sm:w-32">{props.media}</div>}
        </article>
    );
}

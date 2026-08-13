import type { ReactNode } from 'react';
import { cn, requiresTextContainment } from '../lib/utils';

export type EditorialHeaderVariant = 'standard' | 'feature' | 'compact';

export interface EditorialHeaderProps {
    title: string;
    eyebrow?: ReactNode;
    identity?: ReactNode;
    description?: ReactNode;
    media?: ReactNode;
    actions?: ReactNode;
    variant?: EditorialHeaderVariant;
    mediaFirstOnMobile?: boolean;
}

export function EditorialHeader({ title, eyebrow, identity, description, media, actions, variant = 'standard', mediaFirstOnMobile = false }: EditorialHeaderProps) {
    const split = !media && variant === 'standard' && description;
    const boundedTitle = requiresTextContainment(title);

    return (
        <header
            className={cn(
                'grid min-w-0 gap-7 border-b border-line pb-9',
                media && 'lg:grid-cols-12 lg:items-end lg:gap-10',
                split && 'md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] md:items-end md:gap-12',
                variant === 'feature' && 'pb-12',
                variant === 'compact' && 'gap-5 pb-6',
            )}
        >
            <div className={cn('min-w-0', media && 'lg:order-first lg:col-span-7')}>
                {eyebrow && <div className="editorial-eyebrow mb-3">{eyebrow}</div>}
                <h1
                    className={cn(
                        'editorial-title break-words [overflow-wrap:anywhere] text-ink',
                        variant === 'feature' ? 'text-6xl sm:text-[5rem]' : variant === 'compact' ? 'text-4xl sm:text-5xl' : 'text-5xl sm:text-7xl',
                        boundedTitle && 'max-h-72 overflow-hidden py-4 text-3xl leading-[1.5] [contain:paint] sm:text-4xl',
                    )}
                    title={boundedTitle ? title : undefined}
                >
                    {title}
                </h1>
                {identity && <div className="mt-4 min-w-0 font-serif text-xl text-fog sm:text-2xl">{identity}</div>}
                {!split && description && <div className="editorial-copy mt-5 text-base">{description}</div>}
                {!split && actions && <div className="mt-7 flex flex-wrap items-center gap-3">{actions}</div>}
            </div>
            {split && <div className="min-w-0"><div className="editorial-copy text-base">{description}</div>{actions && <div className="mt-7 flex flex-wrap items-center gap-3">{actions}</div>}</div>}
            {media && <div className={cn('min-w-0 lg:order-last lg:col-span-5', mediaFirstOnMobile && 'order-first')}>{media}</div>}
        </header>
    );
}

import { ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import type { DiscoverySection } from '../lib/types';
import { ActivityRail } from './activity-rail';
import { AlbumCard } from './album-card';
import { SectionHeading } from './section-heading';

export function AlbumRail({ section, showReasons = true }: { section: DiscoverySection; showReasons?: boolean }) {
    const isBeyond = section.type === 'beyond-library';
    const moreHref = isBeyond ? '/beyond' : `/discover/lenses/${section.type}`;
    const hasMore = isBeyond || (section.total ?? section.items.length) > section.items.length;
    return (
        <section aria-labelledby={`section-${section.type}`}>
            <SectionHeading
                id={`section-${section.type}`}
                eyebrow={isBeyond ? 'Outside the collection' : 'Discovery lens'}
                title={section.title}
                description={section.description}
                action={hasMore ? <Link to={moreHref} className="inline-flex min-h-11 items-center gap-2 text-sm font-bold text-cobalt outline-none hover:underline focus-visible:ring-2 focus-visible:ring-cobalt">View all <ArrowRight className="size-4" aria-hidden="true" /></Link> : undefined}
            />
            <ActivityRail label={section.title} landmark={false}>
                {section.items.map((item, index) => (
                    <AlbumCard key={item.album.id} album={item.album} index={index} variant="rail" reasons={showReasons && !isBeyond ? item.reasons.filter((reason) => !['time_on_shelf', 'recently_added'].includes(reason.code)) : []} />
                ))}
            </ActivityRail>
        </section>
    );
}

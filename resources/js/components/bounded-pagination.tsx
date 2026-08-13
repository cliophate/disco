import { ArrowLeft, ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Button } from './ui/button';

export function BoundedPagination({ current, last, href, label, noun = 'Page' }: { current: number; last: number; href: (page: number) => string; label: string; noun?: string }) {
    if (last <= 1) return null;

    return (
        <nav aria-label={label} className="mt-12 grid grid-cols-[1fr_auto_1fr] items-center gap-3 border-t border-line pt-6">
            <div>{current > 1 && <Button asChild variant="secondary"><Link to={href(current - 1)}><ArrowLeft className="size-4" />Previous</Link></Button>}</div>
            <span className="text-center text-xs font-semibold text-fog">{noun} {current} of {last}</span>
            <div className="flex justify-end">{current < last && <Button asChild variant="secondary"><Link to={href(current + 1)}>Next<ArrowRight className="size-4" /></Link></Button>}</div>
        </nav>
    );
}

import { Children, useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export interface ActivityRailProps {
    label: string;
    children: ReactNode;
    empty?: ReactNode;
    landmark?: boolean;
}

export function ActivityRail({ label, children, empty, landmark = true }: ActivityRailProps) {
    const items = Children.toArray(children);
    const [scrollState, setScrollState] = useState({ overflow: false, canMoveBack: false, canMoveForward: false });
    const railId = useId();
    const railRef = useRef<HTMLOListElement>(null);
    const measureFrame = useRef<number | null>(null);
    const Root = landmark ? 'section' : 'div';

    const measure = () => {
        const rail = railRef.current;
        if (!rail) return;
        const maximum = Math.max(0, rail.scrollWidth - rail.clientWidth);
        const next = {
            overflow: maximum > 1 && items.length > 1,
            canMoveBack: rail.scrollLeft > 1,
            canMoveForward: rail.scrollLeft < maximum - 1,
        };
        setScrollState((current) => current.overflow === next.overflow
            && current.canMoveBack === next.canMoveBack
            && current.canMoveForward === next.canMoveForward ? current : next);
    };

    const scheduleMeasure = () => {
        if (measureFrame.current !== null) return;
        measureFrame.current = requestAnimationFrame(() => {
            measureFrame.current = null;
            measure();
        });
    };

    useLayoutEffect(() => {
        const rail = railRef.current;
        if (rail) rail.scrollLeft = 0;
    }, [items.length]);

    useEffect(() => {
        const rail = railRef.current;
        if (!rail) return;
        measure();
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(scheduleMeasure);
        observer?.observe(rail);
        if (observer === null) window.addEventListener('resize', scheduleMeasure);

        return () => {
            observer?.disconnect();
            if (observer === null) window.removeEventListener('resize', scheduleMeasure);
            if (measureFrame.current !== null) cancelAnimationFrame(measureFrame.current);
        };
    }, [items.length]);

    if (items.length === 0) {
        return empty === undefined ? null : <Root aria-label={label}><p className="text-sm text-fog">{empty}</p></Root>;
    }

    const move = (direction: -1 | 1) => {
        const rail = railRef.current;
        if (!rail) return;
        const children = Array.from(rail.children) as HTMLElement[];
        const firstOffset = children[0]?.offsetLeft ?? 0;
        const positions = children.map((item) => item.offsetLeft - firstOffset);
        const maximum = Math.max(0, rail.scrollWidth - rail.clientWidth);
        const target = direction > 0
            ? positions.find((position) => position > rail.scrollLeft + 1) ?? maximum
            : positions.slice().reverse().find((position) => position < rail.scrollLeft - 1) ?? 0;
        rail.scrollTo({ left: Math.max(0, Math.min(target, maximum)), behavior: 'auto' });
    };

    return (
        <Root aria-label={label}>
            {scrollState.overflow && (
                <div className="mb-4 flex justify-end gap-2">
                    <button
                        type="button"
                        aria-controls={railId}
                        disabled={!scrollState.canMoveBack}
                        onClick={() => move(-1)}
                        className="inline-flex min-h-11 min-w-11 items-center gap-1 rounded-full border border-line bg-panel px-4 text-sm font-semibold text-ink outline-none hover:border-cobalt hover:text-cobalt focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2 focus-visible:ring-offset-paper disabled:pointer-events-none disabled:opacity-45"
                    >
                        <ChevronLeft className="size-4" aria-hidden="true" />Previous
                    </button>
                    <button
                        type="button"
                        aria-controls={railId}
                        disabled={!scrollState.canMoveForward}
                        onClick={() => move(1)}
                        className="inline-flex min-h-11 min-w-11 items-center gap-1 rounded-full border border-line bg-panel px-4 text-sm font-semibold text-ink outline-none hover:border-cobalt hover:text-cobalt focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2 focus-visible:ring-offset-paper disabled:pointer-events-none disabled:opacity-45"
                    >
                        Next<ChevronRight className="size-4" aria-hidden="true" />
                    </button>
                </div>
            )}
            <ol ref={railRef} id={railId} onScroll={scheduleMeasure} className="rail-scroll -mx-5 flex items-stretch gap-5 overflow-x-auto px-5 pb-5 scroll-ps-5 lg:-mx-8 lg:px-8 lg:scroll-ps-8" tabIndex={0} aria-label={`${label} items`}>
                {items.map((item, index) => <li id={`${railId}-item-${index}`} key={(item as { key?: string }).key ?? index} className="flex min-w-0 shrink-0">{item}</li>)}
            </ol>
        </Root>
    );
}

import { useLayoutEffect, useRef, type HTMLAttributes, type ReactNode } from 'react';
import { cn } from '../lib/utils';

export interface MasonryFeedProps extends HTMLAttributes<HTMLDivElement> {
    children: ReactNode;
}

export type MasonryFeedItemVariant = 'standard' | 'wide' | 'tall' | 'feature';

export interface MasonryFeedItemProps extends HTMLAttributes<HTMLDivElement> {
    variant?: MasonryFeedItemVariant;
}

export function MasonryFeed({ children, className, ...props }: MasonryFeedProps) {
    const gridRef = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const grid = gridRef.current;
        if (!grid) return;
        let frame: number | null = null;
        let observer: ResizeObserver | null = null;
        const mobile = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 39.999rem)') : null;
        const layout = () => {
            frame = null;
            const children = Array.from(grid.children).filter((child): child is HTMLElement => child instanceof HTMLElement);
            if (mobile?.matches) {
                children.forEach((child) => {
                    child.style.gridColumn = '';
                    child.style.gridRow = '';
                });
                return;
            }
            const style = window.getComputedStyle(grid);
            const columns = Math.max(1, style.gridTemplateColumns.split(' ').filter(Boolean).length);
            const rowHeight = Number.parseFloat(style.gridAutoRows) || 8;
            const gap = Number.parseFloat(style.rowGap) || 24;
            const columnEnds = Array.from({ length: columns }, () => 0);
            const measurements = children.map((child) => ({
                child,
                height: child.getBoundingClientRect().height,
            }));
            const placements = measurements.map(({ child, height }) => {
                const requestedSpan = child.dataset.span === 'feature' || child.dataset.span === 'wide' ? 2 : 1;
                const span = Math.min(columns, requestedSpan);
                let selectedColumn = 0;
                let selectedEnd = Number.POSITIVE_INFINITY;
                for (let column = 0; column <= columns - span; column++) {
                    const end = Math.max(...columnEnds.slice(column, column + span));
                    if (end < selectedEnd) {
                        selectedEnd = end;
                        selectedColumn = column;
                    }
                }
                const rowSpan = Math.max(1, Math.ceil((height + gap) / (rowHeight + gap)));
                const rowStart = selectedEnd + 1;
                const lastRow = rowStart + rowSpan - 1;
                for (let column = selectedColumn; column < selectedColumn + span; column++) {
                    columnEnds[column] = lastRow;
                }

                return { child, column: `${selectedColumn + 1} / span ${span}`, row: `${rowStart} / span ${rowSpan}` };
            });
            placements.forEach(({ child, column, row }) => {
                child.style.gridColumn = column;
                child.style.gridRow = row;
            });
        };
        const schedule = () => {
            if (typeof requestAnimationFrame === 'undefined') {
                layout();
                return;
            }
            if (frame !== null) cancelAnimationFrame(frame);
            frame = requestAnimationFrame(layout);
        };
        const connectObserver = () => {
            observer?.disconnect();
            observer = !mobile?.matches && typeof ResizeObserver !== 'undefined' ? new ResizeObserver(schedule) : null;
            Array.from(grid.children).forEach((child) => observer?.observe(child));
            schedule();
        };
        window.addEventListener('resize', schedule);
        mobile?.addEventListener('change', connectObserver);
        connectObserver();

        return () => {
            if (frame !== null && typeof cancelAnimationFrame !== 'undefined') cancelAnimationFrame(frame);
            observer?.disconnect();
            window.removeEventListener('resize', schedule);
            mobile?.removeEventListener('change', connectObserver);
        };
    }, [children]);

    return (
        <div ref={gridRef} className={cn('masonry-feed', className)} {...props}>
            {children}
        </div>
    );
}

export function MasonryFeedItem({ variant = 'standard', className, ...props }: MasonryFeedItemProps) {
    return (
        <div
            data-span={variant}
            className={cn(
                'masonry-feed-item min-w-0',
                className,
            )}
            {...props}
        />
    );
}

import type { ReactNode } from 'react';
import { cn } from '../lib/utils';

export interface CountFilter {
    id: string;
    label: string;
    count: number;
    disabled?: boolean;
}

export interface SortOption {
    value: string;
    label: string;
}

export interface FilterSort {
    label: string;
    value: string;
    options: SortOption[];
    onChange: (value: string) => void;
}

export interface FilterBarProps {
    label: string;
    filters: CountFilter[];
    selected: string;
    onFilterChange: (id: string) => void;
    sort?: FilterSort;
    tabs?: ReactNode;
    controls?: ReactNode;
    disclosure?: ReactNode;
    className?: string;
}

export function FilterBar({ label, filters, selected, onFilterChange, sort, tabs, controls, disclosure, className }: FilterBarProps) {
    return (
        <div className={cn('border-b border-line py-4', className)}>
            {tabs && <div className="mb-4">{tabs}</div>}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div role="group" aria-label={label} className="rail-scroll -mx-5 flex max-w-full flex-nowrap gap-2 overflow-x-auto px-5 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0">
                    {filters.map((filter) => (
                        <button
                            key={filter.id}
                            type="button"
                            aria-pressed={selected === filter.id}
                            disabled={filter.disabled}
                            onClick={() => onFilterChange(filter.id)}
                            className="inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-line bg-panel px-4 text-sm font-semibold text-ink outline-none transition-colors hover:border-cobalt hover:text-cobalt focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2 focus-visible:ring-offset-paper disabled:pointer-events-none disabled:opacity-45 aria-pressed:border-cobalt aria-pressed:bg-cobalt aria-pressed:text-cream"
                        >
                            <span>{filter.label}</span>
                            <span className="text-xs tabular-nums opacity-70">{filter.count.toLocaleString()}</span>
                        </button>
                    ))}
                </div>
                {(controls || (sort && sort.options.length > 0)) && <div className="flex flex-wrap items-end gap-5">
                    {controls}
                    {sort && sort.options.length > 0 && (
                    <label className="flex min-h-11 items-center gap-3 text-xs font-bold uppercase tracking-[0.14em] text-fog">
                        <span>{sort.label}</span>
                        <select
                            value={sort.value}
                            onChange={(event) => sort.onChange(event.target.value)}
                            className="min-h-11 rounded-lg border border-line bg-panel px-3 text-sm font-semibold normal-case tracking-normal text-ink outline-none focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2 focus-visible:ring-offset-paper"
                        >
                            {sort.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </label>
                    )}
                </div>}
            </div>
            {disclosure && <div className="mt-4">{disclosure}</div>}
        </div>
    );
}

import { useId, type ReactNode } from 'react';

export interface CollectionStatProps {
    label: string;
    value: number | string;
    icon?: ReactNode;
}

export function CollectionStat({ label, value, icon }: CollectionStatProps) {
    const displayValue = typeof value === 'number' ? value.toLocaleString() : value;
    const labelId = useId();

    return (
        <div role="group" aria-labelledby={labelId} className="flex min-w-0 items-start justify-between gap-5 py-5">
            <div className="min-w-0">
                <p id={labelId} className="text-xs font-bold uppercase tracking-[0.2em] text-fog">{label}</p>
                <p className="mt-3 break-words [overflow-wrap:anywhere] font-serif text-5xl leading-none tracking-[-0.04em] text-cobalt sm:text-6xl">{displayValue}</p>
            </div>
            {icon && <span aria-hidden="true" className="mt-1 shrink-0 text-coral">{icon}</span>}
        </div>
    );
}

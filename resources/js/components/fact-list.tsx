import type { ReactNode } from 'react';

export interface FactListItem {
    id?: string;
    label: ReactNode;
    value: ReactNode;
}

export interface FactListProps {
    facts: FactListItem[];
    label?: string;
    empty?: ReactNode;
}

function hasValue(value: ReactNode) {
    return value !== null && value !== undefined && value !== false && (typeof value !== 'string' || value.trim() !== '');
}

export function FactList({ facts, label, empty }: FactListProps) {
    const visibleFacts = facts.filter((fact) => hasValue(fact.value));

    if (visibleFacts.length === 0) {
        return empty === undefined ? null : <p className="text-base text-fog">{empty}</p>;
    }

    return (
        <dl aria-label={label} className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
            {visibleFacts.map((fact, index) => (
                <div key={fact.id ?? index} className="min-w-0 border-t border-line pt-3">
                    <dt className="text-xs font-bold uppercase tracking-[0.16em] text-fog">{fact.label}</dt>
                    <dd className="mt-1 break-words [overflow-wrap:anywhere] text-base font-semibold text-ink">{fact.value}</dd>
                </div>
            ))}
        </dl>
    );
}

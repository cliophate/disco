import { useId, useRef, type KeyboardEvent } from 'react';
import { NavLink } from 'react-router-dom';
import { cn } from '../lib/utils';

interface DetailTabBase {
    id: string;
    label: string;
    count?: number;
}

export interface RouteDetailTab extends DetailTabBase {
    to: string;
    end?: boolean;
}

export interface StateDetailTab extends DetailTabBase {
    panelId: string;
    tabId?: string;
}

interface RouteDetailTabsProps {
    mode: 'route';
    label: string;
    tabs: RouteDetailTab[];
}

interface StateDetailTabsProps {
    mode: 'state';
    label: string;
    tabs: StateDetailTab[];
    value: string;
    onValueChange: (value: string) => void;
}

export type DetailTabsProps = RouteDetailTabsProps | StateDetailTabsProps;

function TabLabel({ label, count }: Pick<DetailTabBase, 'label' | 'count'>) {
    return (
        <>
            <span>{label}</span>
            {count !== undefined && <span className="text-xs tabular-nums text-fog">{count.toLocaleString()}</span>}
        </>
    );
}

const tabClassName = 'inline-flex min-h-11 items-center gap-2 border-b-2 !border-transparent px-1 text-sm font-semibold text-fog outline-none transition-colors hover:text-ink focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2 focus-visible:ring-offset-paper';

export function DetailTabs(props: DetailTabsProps) {
    const generatedId = useId();
    const tabRefs = useRef<Array<HTMLButtonElement | null>>([]);

    if (props.mode === 'route') {
        return (
            <nav aria-label={props.label} className="overflow-x-auto border-b border-line">
                <div className="flex min-w-max gap-6">
                    {props.tabs.map((tab) => (
                        <NavLink
                            key={tab.id}
                            to={tab.to}
                            end={tab.end ?? true}
                            className={({ isActive }) => cn(tabClassName, isActive && '!border-coral text-ink')}
                        >
                            <TabLabel label={tab.label} count={tab.count} />
                        </NavLink>
                    ))}
                </div>
            </nav>
        );
    }

    const selectedIndex = props.tabs.findIndex((tab) => tab.id === props.value);
    const focusIndex = selectedIndex === -1 ? 0 : selectedIndex;

    const activate = (index: number) => {
        const tab = props.tabs[index];
        if (!tab) return;
        tabRefs.current[index]?.focus();
        props.onValueChange(tab.id);
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
        let nextIndex: number | null = null;

        if (event.key === 'ArrowRight') nextIndex = (index + 1) % props.tabs.length;
        if (event.key === 'ArrowLeft') nextIndex = (index - 1 + props.tabs.length) % props.tabs.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = props.tabs.length - 1;
        if (nextIndex === null || props.tabs.length === 0) return;

        event.preventDefault();
        activate(nextIndex);
    };

    return (
        <div role="tablist" aria-label={props.label} className="flex gap-6 overflow-x-auto border-b border-line">
            {props.tabs.map((tab, index) => {
                const selected = tab.id === props.value;
                const tabId = tab.tabId ?? `${generatedId}-${tab.id}-tab`;

                return (
                    <button
                        key={tab.id}
                        ref={(element) => { tabRefs.current[index] = element; }}
                        id={tabId}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        aria-controls={tab.panelId}
                        tabIndex={index === focusIndex ? 0 : -1}
                        onClick={() => props.onValueChange(tab.id)}
                        onKeyDown={(event) => handleKeyDown(event, index)}
                        className={cn(tabClassName, 'shrink-0', selected && '!border-coral text-ink')}
                    >
                        <TabLabel label={tab.label} count={tab.count} />
                    </button>
                );
            })}
        </div>
    );
}

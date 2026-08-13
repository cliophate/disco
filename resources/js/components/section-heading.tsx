import type { ReactNode } from 'react';

export function SectionHeading({ id, eyebrow, title, description, action }: { id?: string; eyebrow?: string; title: string; description?: string; action?: ReactNode }) {
    return (
        <header className="mb-6 flex flex-col justify-between gap-4 border-t border-line pt-5 sm:flex-row sm:items-end">
            <div>
                {eyebrow && <p className="editorial-eyebrow mb-2">{eyebrow}</p>}
                <h2 id={id} className="font-serif text-3xl font-bold leading-none tracking-[-0.03em] sm:text-[2.5rem]">{title}</h2>
                {description && <p className="mt-3 max-w-2xl text-base leading-7 text-fog">{description}</p>}
            </div>
            {action}
        </header>
    );
}

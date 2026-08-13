import type { ReactNode } from 'react';
import { EditorialHeader } from './editorial-header';

export function PageHeading({ eyebrow, title, description, action }: { eyebrow: string; title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="mb-10">
            <EditorialHeader eyebrow={eyebrow} title={title} description={description} actions={action} />
        </div>
    );
}

import { AlertCircle, Inbox } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from './ui/button';
import { Skeleton } from './ui/skeleton';

export function AlbumGridSkeleton({ count = 8 }: { count?: number }) {
    return (
        <div className="cover-grid" aria-label="Loading albums" role="status">
            {Array.from({ length: count }, (_, index) => (
                <div key={index}>
                     <Skeleton className="aspect-square rounded-none" />
                    <Skeleton className="mt-4 h-4 w-2/3" />
                    <Skeleton className="mt-2 h-3 w-1/2" />
                </div>
            ))}
        </div>
    );
}

export function EmptyState({ title, message, action }: { title: string; message: string; action?: ReactNode }) {
    return (
        <div className="grid min-h-72 place-items-center border border-dashed border-line bg-panel px-6 text-center">
            <div>
                <Inbox className="mx-auto size-7 text-fog" aria-hidden="true" />
                <h2 className="mt-4 font-serif text-3xl font-bold">{title}</h2>
                <p className="mt-2 max-w-sm text-base leading-7 text-fog">{message}</p>
                {action && <div className="mt-5">{action}</div>}
            </div>
        </div>
    );
}

export function ErrorState({ error, retry }: { error: unknown; retry?: () => void }) {
    return (
        <div className="grid min-h-64 place-items-center border border-coral/30 bg-coral/5 px-6 text-center" role="alert">
            <div>
                <AlertCircle className="mx-auto size-7 text-coral" aria-hidden="true" />
                <h2 className="mt-4 font-serif text-2xl font-bold">Something slipped off beat</h2>
                <p className="mt-2 text-base leading-7 text-fog">{error instanceof Error ? error.message : 'Please try that request again.'}</p>
                {retry && <Button className="mt-5" variant="secondary" onClick={retry}>Try again</Button>}
            </div>
        </div>
    );
}

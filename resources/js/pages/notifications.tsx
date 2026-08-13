import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowUpRight, Check, Circle } from 'lucide-react';
import { useEffect } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { BoundedPagination } from '../components/bounded-pagination';
import { DetailTabs } from '../components/detail-tabs';
import { EmptyState, ErrorState } from '../components/states';
import { Button } from '../components/ui/button';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';
import type { NotificationFilter, UpcomingNotification } from '../lib/types';

function NotificationRow({ notification, returnContext }: { notification: UpcomingNotification; returnContext: string }) {
    const queryClient = useQueryClient();
    const readState = useMutation({
        mutationFn: (read: boolean) => api.updateNotification(notification.id, read),
        onSuccess: () => Promise.all([
            queryClient.invalidateQueries({ queryKey: ['notifications'] }),
            queryClient.invalidateQueries({ queryKey: ['me'] }),
        ]),
    });
    const date = new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${notification.release_date}T00:00:00Z`));

    return <li className={`grid gap-5 border-t border-line py-6 md:grid-cols-[minmax(0,1fr)_auto] md:items-start ${notification.read ? 'text-fog' : 'text-ink'}`}>
        <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2 text-xs font-bold uppercase tracking-[0.14em] text-fog">
                {!notification.read && <span className="text-coral">Unread</span>}
                <span>{notification.primary_type}</span><span aria-hidden="true">·</span><span>{date}</span>
                {notification.status !== 'active' && <span className={notification.status === 'withdrawn' ? 'text-coral-deep' : ''}>{notification.status}</span>}
            </div>
            <h2 className="mt-2 break-words font-serif text-2xl font-bold leading-tight"><Link to={notification.links.album} state={{ from: returnContext, label: 'notifications' }} className="underline decoration-line underline-offset-4 hover:text-cobalt">{notification.title}</Link></h2>
            <p className="mt-1 font-semibold">{notification.artist}</p>
            <p className="mt-3 max-w-3xl text-sm leading-6 text-fog">{notification.personalization.reason}</p>
            <p className="mt-2 max-w-3xl text-sm leading-6 text-fog">{notification.status_detail}</p>
            <p className="mt-3 text-xs text-fog">Source: <a href={notification.source.url} target="_blank" rel="noreferrer" className="font-semibold underline decoration-line underline-offset-2 hover:text-cobalt">{notification.source.provider_name}<span className="sr-only"> (opens in a new tab)</span></a></p>
        </div>
        <div className="flex flex-wrap gap-2 md:justify-end">
            {notification.status !== 'resolved' && <Button variant="secondary" size="sm" onClick={() => readState.mutate(!notification.read)} disabled={readState.isPending}>
                {notification.read ? <Circle className="size-4" /> : <Check className="size-4" />}{notification.read ? 'Mark unread' : 'Mark read'}
            </Button>}
            <Button asChild variant="ghost" size="sm"><Link to={notification.links.upcoming}>Upcoming <ArrowUpRight className="size-4" /></Link></Button>
        </div>
    </li>;
}

export function NotificationsPage() {
    const location = useLocation();
    const [params, setParams] = useSearchParams();
    const requestedFilter = params.get('filter');
    const filter: NotificationFilter = requestedFilter === 'unread' || requestedFilter === 'active' ? requestedFilter : 'all';
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const notifications = useQuery({ queryKey: ['notifications', filter, page], queryFn: () => api.notifications(filter, page, 25), placeholderData: (previous) => previous });
    useEffect(() => {
        if (!notifications.data || notifications.isPlaceholderData || notifications.data.meta.current_page === page) return;
        const next = new URLSearchParams(params);
        notifications.data.meta.current_page === 1 ? next.delete('page') : next.set('page', String(notifications.data.meta.current_page));
        setParams(next, { replace: true });
    }, [notifications.data, notifications.isPlaceholderData, page, params, setParams]);
    const updateFilter = (value: string) => { const next = new URLSearchParams(params); value === 'all' ? next.delete('filter') : next.set('filter', value); next.delete('page'); setParams(next); };
    const href = (target: number) => { const next = new URLSearchParams(params); target === 1 ? next.delete('page') : next.set('page', String(target)); return `?${next.toString()}`; };
    const returnContext = `${location.pathname}${location.search}`;

    return <div>
        <header className="grid gap-6 border-y border-line py-7 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] md:items-end md:gap-12">
            <div><p className="editorial-eyebrow">Updates</p><h1 className="mt-3 font-serif text-5xl font-bold leading-[0.92] tracking-[-0.045em] sm:text-6xl">Notifications</h1></div>
            <p className="max-w-3xl text-sm leading-7 text-fog">Upcoming albums and EPs from followed artists and confirmed active library matches. Dates and lifecycle changes come from the persisted release cache.</p>
        </header>
        <div className="mt-7"><DetailTabs mode="state" label="Notification views" value={filter} onValueChange={updateFilter} tabs={[
            { id: 'all', label: 'All', panelId: 'notification-panel', tabId: 'notification-all-tab' },
            { id: 'unread', label: 'Unread', panelId: 'notification-panel', tabId: 'notification-unread-tab' },
            { id: 'active', label: 'Active', panelId: 'notification-panel', tabId: 'notification-active-tab' },
        ]} /></div>
        <section id="notification-panel" role="tabpanel" aria-labelledby={`notification-${filter}-tab`} className="mt-8">
            {notifications.isLoading ? <div role="status" aria-label="Loading notifications" className="space-y-4"><Skeleton className="h-36 rounded-none" /><Skeleton className="h-36 rounded-none" /></div>
                : notifications.isError ? <ErrorState error={notifications.error} retry={() => notifications.refetch()} />
                    : !notifications.data?.data.length ? <EmptyState title="No notifications in this view" message="The scheduled generator will add exact personalized upcoming releases here." />
                        : <ul className="border-b border-line">{notifications.data.data.map((notification) => <NotificationRow key={notification.id} notification={notification} returnContext={returnContext} />)}</ul>}
        </section>
        {notifications.data && <BoundedPagination current={notifications.data.meta.current_page} last={notifications.data.meta.last_page} href={href} label="Notification pages" />}
    </div>;
}

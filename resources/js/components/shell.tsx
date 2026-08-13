import { useMutation } from '@tanstack/react-query';
import { Bell, Compass, Disc3, Library, ListMusic, LogOut, Menu, Moon, Orbit, Search, Sparkles, Sun, Users, X } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useState } from 'react';
import { NavLink, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../lib/api';
import type { User } from '../lib/types';
import { cn } from '../lib/utils';
import { Button } from './ui/button';
import { Input } from './ui/input';

const navigation = [
    { to: '/', label: 'Home', icon: Sparkles, end: true },
    { to: '/discover', label: 'Discover', icon: Compass },
    { to: '/beyond', label: 'Beyond', icon: Orbit },
    { to: '/library/albums', label: 'Albums', icon: Library },
    { to: '/want-to-listen', label: 'Want to listen', icon: ListMusic },
    { to: '/artists', label: 'Artists', icon: Users },
    { to: '/search', label: 'Search', icon: Search },
];

type Theme = 'light' | 'dark';
const themeStorageKey = 'disco-theme';

function initialTheme(): Theme {
    let stored: string | null = null;
    try {
        stored = window.localStorage?.getItem(themeStorageKey) ?? null;
    } catch {
        // Storage can be unavailable in hardened browser contexts.
    }
    return stored === 'light' || stored === 'dark'
        ? stored
        : typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

const initialThemeValue = initialTheme();
document.documentElement.dataset.theme = initialThemeValue;

export function Shell({ user, children }: { user: User; children: ReactNode }) {
    const navigate = useNavigate();
    const [params] = useSearchParams();
    const [query, setQuery] = useState(params.get('q') ?? '');
    const [menuOpen, setMenuOpen] = useState(false);
    const [theme, setTheme] = useState<Theme>(initialThemeValue);
    const logout = useMutation({
        mutationFn: api.logout,
        onSuccess: () => window.location.assign('/login'),
    });
    const notificationLabel = user.unread_notification_count === 0
        ? 'Notifications, no unread notifications'
        : `Notifications, ${user.unread_notification_count.toLocaleString()} unread ${user.unread_notification_count === 1 ? 'notification' : 'notifications'}`;
    const notificationCount = user.unread_notification_count > 99 ? '99+' : String(user.unread_notification_count);

    useEffect(() => setQuery(params.get('q') ?? ''), [params]);
    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        try {
            window.localStorage?.setItem(themeStorageKey, theme);
        } catch {
            // The view still changes for this session when storage is unavailable.
        }
    }, [theme]);

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        const trimmed = query.trim();
        setMenuOpen(false);
        navigate(trimmed ? `/search?q=${encodeURIComponent(trimmed)}` : '/search');
    }

    return (
        <div className="flex min-h-screen flex-col">
            <a href="#main-content" className="fixed left-3 top-3 z-[60] -translate-y-20 rounded-full bg-cobalt px-4 py-2 text-sm font-bold text-cream transition focus:translate-y-0">Skip to content</a>
            <header className="mobile-scroll-header sticky top-0 z-50 border-b border-line bg-paper/95 backdrop-blur-xl">
                <div className="site-frame page-gutter mx-auto flex h-16 items-center gap-5">
                    <NavLink to="/" aria-label="Disco home" className="flex shrink-0 items-center gap-2 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-cobalt">
                        <span className="relative grid size-8 place-items-center rounded-full bg-cobalt text-cream"><Disc3 className="size-4" /><span className="absolute -right-0.5 -top-0.5 size-2 rounded-full bg-coral ring-2 ring-paper" /></span>
                        <span className="font-serif text-xl italic tracking-[-0.04em]">disco</span>
                    </NavLink>

                    <nav className="hidden items-center gap-1 xl:flex" aria-label="Primary navigation">
                        {navigation.map(({ to, label, end }) => (
                            <NavLink key={to} to={to} end={end} className={({ isActive }) => cn('inline-flex min-h-11 items-center rounded-full px-3 text-sm font-semibold text-fog outline-none transition hover:bg-raised hover:text-ink focus-visible:ring-2 focus-visible:ring-cobalt', isActive && 'bg-raised text-ink')}>{label}</NavLink>
                        ))}
                    </nav>

                    <form onSubmit={submitSearch} role="search" className="relative ml-auto hidden w-full max-w-xs md:block">
                        <Search className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-fog" />
                        <Input value={query} onChange={(event) => setQuery(event.target.value)} className="bg-panel pl-10 text-sm" placeholder="Search your library" aria-label="Search albums and artists" />
                    </form>

                    <div className="hidden items-center gap-3 md:flex">
                        <NavLink to="/notifications" aria-label={notificationLabel} title={notificationLabel} className={({ isActive }) => cn('relative grid size-11 place-items-center rounded-full text-fog outline-none transition hover:bg-raised hover:text-ink focus-visible:ring-2 focus-visible:ring-cobalt', isActive && 'bg-raised text-ink')}>
                            <Bell className="size-4" />
                            {user.unread_notification_count > 0 && <span aria-hidden="true" className="absolute right-0 top-0 grid min-h-5 min-w-5 place-items-center rounded-full bg-coral px-1 text-[10px] font-bold leading-none text-cream ring-2 ring-paper">{notificationCount}</span>}
                        </NavLink>
                        <NavLink to="/admin" className="max-w-28 truncate rounded-sm text-sm font-semibold text-fog outline-none transition hover:text-ink focus-visible:ring-2 focus-visible:ring-cobalt" title={`${user.email} · Owner administration`}>{user.name ?? user.email.split('@')[0]}</NavLink>
                        <Button variant="ghost" size="icon" onClick={() => setTheme((current) => current === 'dark' ? 'light' : 'dark')} aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} view`} title={`Switch to ${theme === 'dark' ? 'light' : 'dark'} view`}>
                            {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                        </Button>
                        <Button variant="ghost" size="icon" onClick={() => logout.mutate()} disabled={logout.isPending} aria-label="Sign out"><LogOut className="size-4" /></Button>
                    </div>

                    <NavLink to="/notifications" aria-label={notificationLabel} title={notificationLabel} className={({ isActive }) => cn('relative ml-auto grid size-11 place-items-center rounded-full text-fog outline-none transition hover:bg-raised hover:text-ink focus-visible:ring-2 focus-visible:ring-cobalt md:hidden', isActive && 'bg-raised text-ink')}>
                        <Bell className="size-4" />
                        {user.unread_notification_count > 0 && <span aria-hidden="true" className="absolute right-0 top-0 grid min-h-5 min-w-5 place-items-center rounded-full bg-coral px-1 text-[10px] font-bold leading-none text-cream ring-2 ring-paper">{notificationCount}</span>}
                    </NavLink>
                    <button type="button" className="grid size-11 place-items-center rounded-full text-ink outline-none hover:bg-raised focus-visible:ring-2 focus-visible:ring-cobalt xl:hidden" onClick={() => setMenuOpen((open) => !open)} aria-expanded={menuOpen} aria-controls="mobile-navigation" aria-label={menuOpen ? 'Close navigation' : 'Open navigation'}>
                        {menuOpen ? <X className="size-5" /> : <Menu className="size-5" />}
                    </button>
                </div>

                {menuOpen && (
                    <div id="mobile-navigation" className="page-gutter border-t border-line bg-panel py-5 xl:hidden">
                        <form onSubmit={submitSearch} role="search" className="relative mb-5 md:hidden">
                            <Search className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-fog" />
                            <Input value={query} onChange={(event) => setQuery(event.target.value)} className="pl-10" placeholder="Search your library" aria-label="Search albums and artists" />
                        </form>
                        <nav className="grid gap-1" aria-label="Mobile navigation">
                            {navigation.map(({ to, label, icon: Icon, end }) => (
                                <NavLink key={to} to={to} end={end} onClick={() => setMenuOpen(false)} className={({ isActive }) => cn('flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold text-fog outline-none hover:bg-raised hover:text-ink focus-visible:ring-2 focus-visible:ring-cobalt', isActive && 'bg-raised text-ink')}><Icon className="size-4" />{label}</NavLink>
                            ))}
                        </nav>
                        <div className="mt-5 flex items-center justify-between border-t border-line pt-5">
                            <NavLink to="/admin" onClick={() => setMenuOpen(false)} className="min-w-0 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-cobalt" aria-label={`${user.name ?? user.email.split('@')[0]}, owner administration`}><p className="truncate text-sm font-semibold">{user.name ?? user.email.split('@')[0]}</p><p className="truncate text-xs text-fog">{user.email}</p></NavLink>
                            <div className="flex items-center gap-2">
                                <Button variant="ghost" size="icon" onClick={() => setTheme((current) => current === 'dark' ? 'light' : 'dark')} aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} view`}>
                                    {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => logout.mutate()} disabled={logout.isPending}><LogOut className="size-4" />Sign out</Button>
                            </div>
                        </div>
                    </div>
                )}
            </header>
            <main id="main-content" tabIndex={-1} className="site-frame page-gutter mx-auto flex-1 py-8 outline-none lg:py-12">{children}</main>
            <footer className="site-frame page-gutter mx-auto mt-10 flex items-center justify-between border-t border-line py-7 text-sm text-fog">
                <p>Private collection workspace</p>
                <nav aria-label="Utility navigation">
                    <NavLink to="/metadata" className={({ isActive }) => cn('inline-flex min-h-11 items-center gap-2 rounded-full px-3 font-semibold outline-none transition hover:bg-raised hover:text-ink focus-visible:ring-2 focus-visible:ring-cobalt', isActive && 'bg-raised text-ink')}><Disc3 className="size-4" />Metadata</NavLink>
                </nav>
            </footer>
        </div>
    );
}

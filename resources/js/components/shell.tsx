import { useMutation } from '@tanstack/react-query';
import { Disc3, Moon, Sun } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { NavLink } from 'react-router-dom';
import { api } from '../lib/api';
import type { User } from '../lib/types';
import { Button } from './ui/button';

function initialTheme(): 'light' | 'dark' {
    try {
        const stored = localStorage.getItem('disco-theme');
        if (stored === 'light' || stored === 'dark') return stored;
    } catch { /* Storage can be unavailable. */ }
    return typeof matchMedia === 'function' && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function Shell({ user, children }: { user: User; children: ReactNode }) {
    const [theme, setTheme] = useState(initialTheme);
    const logout = useMutation({ mutationFn: api.logout, onSuccess: () => location.assign('/login') });
    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        try { localStorage.setItem('disco-theme', theme); } catch { /* Theme still works for this session. */ }
    }, [theme]);
    return <div className="flex min-h-screen flex-col">
        <a href="#main-content" className="sr-only focus:not-sr-only focus:p-3">Skip to content</a>
        <header className="border-b border-line">
            <div className="site-frame page-gutter mx-auto flex min-h-20 flex-wrap items-center gap-2 py-2 sm:gap-5">
                <NavLink to="/" aria-label="Disco home" className="mr-3 flex min-h-11 items-center gap-2 font-serif text-2xl italic"><Disc3 className="size-7 text-cobalt" />disco</NavLink>
                <nav aria-label="Primary navigation" className="flex items-center gap-4">
                    <NavLink to="/" end className={({ isActive }) => `inline-flex min-h-11 items-center border-b-2 text-sm font-semibold ${isActive ? 'border-coral' : 'border-transparent text-fog'}`}>This week</NavLink>
                    <NavLink to="/admin" title={user.email} className={({ isActive }) => `inline-flex min-h-11 items-center border-b-2 text-sm ${isActive ? 'border-coral' : 'border-transparent text-fog'}`}>Admin</NavLink>
                </nav>
                <div className="ml-auto flex items-center gap-1">
                    <Button variant="ghost" size="icon" onClick={() => setTheme(theme === 'light' ? 'dark' : 'light')} aria-label={`Switch to ${theme === 'light' ? 'dark' : 'light'} view`}>{theme === 'light' ? <Moon className="size-4" /> : <Sun className="size-4" />}</Button>
                    <Button variant="ghost" onClick={() => logout.mutate()} disabled={logout.isPending}>Sign out</Button>
                </div>
                {logout.isError && <p role="alert" className="w-full text-sm text-coral">{logout.error.message}</p>}
            </div>
        </header>
        <main id="main-content" tabIndex={-1} className="site-frame page-gutter mx-auto flex-1 py-10 outline-none lg:py-16">{children}</main>
    </div>;
}

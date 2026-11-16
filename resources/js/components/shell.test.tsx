import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Shell } from './shell';
vi.mock('../lib/api', () => ({ api: { logout: vi.fn() } }));
afterEach(cleanup);
it('has one destination plus admin and changes themes', () => {
    localStorage.clear();
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><Shell user={{ id: 'fixture', email: 'owner@example.test' }}><h1>This week</h1></Shell></MemoryRouter></QueryClientProvider>);
    expect(screen.getByRole('navigation', { name: 'Primary navigation' }).querySelectorAll('a')).toHaveLength(2);
    expect(screen.queryByRole('search')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Switch to dark view' }));
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(localStorage.getItem('disco-theme')).toBe('dark');
    fireEvent.click(screen.getByRole('button', { name: 'Switch to light view' }));
    expect(document.documentElement.dataset.theme).toBe('light');
});

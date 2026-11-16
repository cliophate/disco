import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { Shell } from './components/shell';
import { ErrorState } from './components/states';
import { Skeleton } from './components/ui/skeleton';
import { ApiError, api } from './lib/api';
import { AdminPage } from './pages/admin';
import { ThisWeekPage } from './pages/this-week';
import { LoginPage } from './pages/login';
import { NotFoundPage } from './pages/not-found';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: { staleTime: 30_000, retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2 },
    },
});

function AuthenticatedApp() {
    const me = useQuery({ queryKey: ['me'], queryFn: api.me });

    if (me.isLoading) return <div className="min-h-screen" role="status" aria-label="Loading application"><Skeleton className="h-16 rounded-none border-b border-line" /><div className="mx-auto max-w-[1600px] p-5 lg:p-8"><Skeleton className="mt-8 h-[65vh] rounded-none" /></div></div>;
    if (me.error instanceof ApiError && me.error.status === 401) return <LoginPage />;
    if (me.isError) return <main className="grid min-h-screen place-items-center p-6"><div className="w-full max-w-xl"><ErrorState error={me.error} retry={() => me.refetch()} /></div></main>;
    if (!me.data) return <LoginPage />;

    return (
        <Shell user={me.data}>
            <Routes>
                <Route path="/" element={<ThisWeekPage />} />
                <Route path="/admin" element={<AdminPage />} />
                <Route path="*" element={<NotFoundPage />} />
            </Routes>
        </Shell>
    );
}

const root = document.getElementById('app');
if (!root) throw new Error('Application mount point not found.');

createRoot(root).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <BrowserRouter><AuthenticatedApp /></BrowserRouter>
        </QueryClientProvider>
    </StrictMode>,
);

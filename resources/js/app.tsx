import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { Shell } from './components/shell';
import { PlaybackProvider } from './components/playback-provider';
import { ErrorState } from './components/states';
import { Skeleton } from './components/ui/skeleton';
import { ApiError, api } from './lib/api';
import { AlbumDetailPage } from './pages/album-detail';
import { AdminPage } from './pages/admin';
import { ArtistDetailPage } from './pages/artist-detail';
import { ArtistsPage } from './pages/artists';
import { BeyondPage } from './pages/beyond';
import { DiscoverPage } from './pages/discover';
import { HomePage } from './pages/home';
import { HomeLensPage } from './pages/home-lens';
import { LibraryPage } from './pages/library';
import { LoginPage } from './pages/login';
import { MetadataPage } from './pages/metadata';
import { NotificationsPage } from './pages/notifications';
import { NotFoundPage } from './pages/not-found';
import { SearchPage } from './pages/search';
import { UpcomingPage } from './pages/upcoming';
import { WantToListenPage } from './pages/want-to-listen';

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
        <PlaybackProvider>
            <Shell user={me.data}>
                <Routes>
                <Route path="/" element={<HomePage />} />
                <Route path="/discover" element={<DiscoverPage />} />
                <Route path="/discover/upcoming" element={<UpcomingPage />} />
                <Route path="/discover/lenses/:lens" element={<HomeLensPage />} />
                <Route path="/beyond" element={<BeyondPage />} />
                <Route path="/library/albums" element={<LibraryPage />} />
                <Route path="/want-to-listen" element={<WantToListenPage />} />
                <Route path="/albums/:id" element={<AlbumDetailPage />} />
                <Route path="/artists" element={<ArtistsPage />} />
                <Route path="/artists/:id" element={<ArtistDetailPage />} />
                <Route path="/search" element={<SearchPage />} />
                <Route path="/metadata" element={<MetadataPage />} />
                <Route path="/admin" element={<AdminPage />} />
                <Route path="/notifications" element={<NotificationsPage />} />
                <Route path="*" element={<NotFoundPage />} />
                </Routes>
            </Shell>
        </PlaybackProvider>
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

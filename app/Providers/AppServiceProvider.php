<?php

namespace App\Providers;

use App\Models\User;
use App\Music\Admin\ProviderCredentialResolver;
use App\Music\Descriptions\TheAudioDbClient;
use App\Music\Discogs\DiscogsClient;
use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\Notifications\GotifyClient;
use App\Music\Plex\PlexClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ProviderCredentialResolver::class);

        $this->app->scoped(PlexClient::class, fn ($app): PlexClient => new PlexClient(
            token: $app->make(ProviderCredentialResolver::class)->resolve('plex')['secret'],
        ));
        $this->app->scoped(ListenBrainzClient::class, fn ($app): ListenBrainzClient => new ListenBrainzClient(
            token: $app->make(ProviderCredentialResolver::class)->resolve('listenbrainz')['secret'],
        ));
        $this->app->scoped(DiscogsClient::class, fn ($app): DiscogsClient => new DiscogsClient(
            $app->make(ProviderCredentialResolver::class)->resolve('discogs')['secret'],
        ));
        $this->app->scoped(GotifyClient::class, fn ($app): GotifyClient => new GotifyClient(
            $app->make(ProviderCredentialResolver::class)->resolve('gotify')['secret'],
        ));
        $this->app->scoped(TheAudioDbClient::class, fn ($app): TheAudioDbClient => new TheAudioDbClient(
            $app->make(ProviderCredentialResolver::class)->resolve('theaudiodb')['secret'],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('owner', fn (User $user): bool => $user->is_owner);

        if (! $this->app->isProduction()) {
            return;
        }

        $requirements = [
            [! config('app.debug'), 'APP_DEBUG must be false'],
            [str_starts_with((string) config('app.url'), 'https://'), 'APP_URL must use HTTPS'],
            [(bool) config('session.secure'), 'SESSION_SECURE_COOKIE must be true'],
            [str_starts_with((string) config('services.plex.url'), 'https://'), 'PLEX_URL must use HTTPS'],
            [filled(config('services.plex.expected_machine_identifier')), 'PLEX_EXPECTED_MACHINE_IDENTIFIER is required'],
            [filled(config('services.plex.expected_library_uuid')), 'PLEX_EXPECTED_LIBRARY_UUID is required'],
        ];

        foreach ($requirements as [$valid, $message]) {
            if (! $valid) {
                throw new RuntimeException("Unsafe production configuration: {$message}.");
            }
        }
    }
}

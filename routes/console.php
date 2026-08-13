<?php

use App\Models\SourceProvider;
use App\Music\Admin\ProviderCredentialResolver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('disco:plex-sync')
    ->hourly()
    ->withoutOverlapping(55)
    ->onOneServer();
Schedule::command('disco:plex-playback-context')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
Schedule::command('disco:plex-artwork --failed-only')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('disco:musicbrainz-enrich --limit=50')
    ->dailyAt('03:00')
    ->withoutOverlapping(120)
    ->onOneServer();
Schedule::command('disco:artwork-prune --grace-days=7')
    ->weeklyOn(1, '04:00')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('disco:listenbrainz-sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14)
    ->onOneServer();
Schedule::command('disco:listenbrainz-sync --full')
    ->weeklyOn(1, '01:37')
    ->withoutOverlapping(360)
    ->onOneServer();
Schedule::command('disco:recommendation-prune')
    ->weeklyOn(1, '04:30')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('disco:listenbrainz-recommendations')
    ->dailyAt('03:30')
    ->withoutOverlapping(120)
    ->onOneServer();
Schedule::command('disco:upcoming-releases')
    ->dailyAt('03:45')
    ->withoutOverlapping(30)
    ->onOneServer();
Schedule::command('disco:upcoming-notifications')
    ->dailyAt('04:00')
    ->withoutOverlapping(30)
    ->onOneServer();
Schedule::command('disco:upcoming-notification-delivery --limit=50')
    ->cron('5,20,35,50 * * * *')
    ->withoutOverlapping(14)
    ->onOneServer();
Schedule::command('disco:manual-operations-reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping(4)
    ->onOneServer();
Schedule::command('disco:beyond-enrich --limit=50')
    ->dailyAt('04:15')
    ->withoutOverlapping(120)
    ->onOneServer();
Schedule::command('disco:album-narratives --scope=beyond --limit=30')
    ->dailyAt('04:45')
    ->withoutOverlapping(120)
    ->createMutexNameUsing('disco:narrative-enrichment')
    ->onOneServer();
Schedule::command('disco:album-narratives --scope=owned --limit=20')
    ->dailyAt('05:15')
    ->withoutOverlapping(120)
    ->createMutexNameUsing('disco:narrative-enrichment')
    ->onOneServer();
Schedule::command('disco:artist-biographies --limit=20')
    ->dailyAt('05:45')
    ->withoutOverlapping(120)
    ->createMutexNameUsing('disco:narrative-enrichment')
    ->onOneServer();
Schedule::command('disco:credits --limit=20')
    ->dailyAt('06:15')
    ->withoutOverlapping(180)
    ->onOneServer();
Schedule::command('disco:artist-discographies --limit=2')
    ->everyFifteenMinutes()
    ->withoutOverlapping(60)
    ->onOneServer();
Schedule::command('disco:catalog-enrich --limit=50')
    ->everyTenMinutes()
    ->withoutOverlapping(9)
    ->onOneServer();
Schedule::command('disco:discogs-enrich --limit=20')
    ->everyTenMinutes()
    ->when(fn (): bool => app(ProviderCredentialResolver::class)->resolve('discogs')['configured'])
    ->withoutOverlapping(9)
    ->onOneServer();
Schedule::command('disco:pitchfork-rss')
    ->dailyAt('07:15')
    ->when(fn (): bool => (bool) config('discovery.editorial.pitchfork.enabled')
        || SourceProvider::query()->where('slug', 'pitchfork')->where('enabled', true)->exists())
    ->withoutOverlapping(15)
    ->onOneServer();

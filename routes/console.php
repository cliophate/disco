<?php

use App\Music\Discovery\DiscoveryArtworkRefresher;
use App\Music\Discovery\DiscoveryBatchGenerator;
use App\Music\Discovery\PickStateReconciler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('disco:discovery-new-artists', function (DiscoveryBatchGenerator $generator): void {
    $batch = $generator->generate('new_artists');
    $this->info('New artists: '.$batch->summary['picks'].' picks.');
})->purpose('Generate the weekly new-artist album batch');
Artisan::command('disco:discovery-new-releases', function (DiscoveryBatchGenerator $generator): void {
    $batch = $generator->generate('new_releases');
    $this->info('New releases: '.$batch->summary['picks'].' picks.');
})->purpose('Refresh personal and candidate-pool new releases');
Artisan::command('disco:discovery-more', function (DiscoveryBatchGenerator $generator): void {
    $batch = $generator->generate('more');
    $this->info('More from artists you play: '.$batch->summary['picks'].' picks.');
})->purpose('Generate unheard albums from high-taste artists');
Artisan::command('disco:discovery-reconcile', function (PickStateReconciler $reconciler): void {
    $this->info('Updated '.$reconciler->reconcile().' picks.');
})->purpose('Reconcile discovery states with Plex and listening history');

Artisan::command('disco:discovery-artwork', function (DiscoveryArtworkRefresher $artwork): void {
    $this->info('Updated '.$artwork->refresh().' discovery covers.');
})->purpose('Retry missing discovery covers, including newly released albums');

Schedule::command('disco:plex-sync')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('disco:listenbrainz-sync')->everyFifteenMinutes()->withoutOverlapping(14)->onOneServer();
Schedule::command('disco:listenbrainz-sync --full')->weeklyOn(1, '01:37')->withoutOverlapping(360)->onOneServer();
Schedule::command('disco:musicbrainz-enrich --limit=50')->dailyAt('03:00')->withoutOverlapping(120)->onOneServer();
Schedule::command('disco:plex-artwork --failed-only')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('disco:artwork-prune --grace-days=7')->weeklyOn(1, '04:00')->withoutOverlapping()->onOneServer();
Schedule::command('disco:discovery-new-artists')->weeklyOn(1, '05:00')->withoutOverlapping(360)->onOneServer();
Schedule::command('disco:discovery-new-releases')->dailyAt('07:00')->withoutOverlapping(360)->onOneServer();
Schedule::command('disco:discovery-more')->weeklyOn(1, '08:00')->withoutOverlapping(360)->onOneServer();
Schedule::command('disco:discovery-artwork')->dailyAt('07:45')->withoutOverlapping(120)->onOneServer();

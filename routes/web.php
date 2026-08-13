<?php

use App\Http\Controllers\Api\AdminOverviewController;
use App\Http\Controllers\Api\AlbumController;
use App\Http\Controllers\Api\AlbumListController;
use App\Http\Controllers\Api\AlbumListStateController;
use App\Http\Controllers\Api\ArtistController;
use App\Http\Controllers\Api\ArtistDiscographyController;
use App\Http\Controllers\Api\ArtistDiscographyRefreshController;
use App\Http\Controllers\Api\ArtistFollowController;
use App\Http\Controllers\Api\ArtistIndexController;
use App\Http\Controllers\Api\ArtworkController;
use App\Http\Controllers\Api\BeyondLibraryController;
use App\Http\Controllers\Api\DiscoverController;
use App\Http\Controllers\Api\EntityArtworkController;
use App\Http\Controllers\Api\ExternalCatalogController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\HomeLensController;
use App\Http\Controllers\Api\LibraryAlbumController;
use App\Http\Controllers\Api\ManualOperationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MetadataCoverageController;
use App\Http\Controllers\Api\MetadataDiagnosticsController;
use App\Http\Controllers\Api\PipelineDiagnosticsController;
use App\Http\Controllers\Api\PlaybackSessionController;
use App\Http\Controllers\Api\PlaybackStreamController;
use App\Http\Controllers\Api\PlexOpenTargetController;
use App\Http\Controllers\Api\ProviderCredentialController;
use App\Http\Controllers\Api\RecommendationFeedbackController;
use App\Http\Controllers\Api\RetryMetadataDiagnosticController;
use App\Http\Controllers\Api\RetryPipelineDiagnosticController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\UpcomingReleaseController;
use App\Http\Controllers\Api\UpcomingReleaseNotificationController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::middleware('auth')->post('/auth/logout', [AuthController::class, 'logout']);

Route::prefix('api/v1')->middleware('auth')->group(function (): void {
    Route::get('/me', MeController::class);
    Route::prefix('admin')->middleware('can:owner')->group(function (): void {
        Route::get('/overview', AdminOverviewController::class);
        Route::get('/providers', [ProviderCredentialController::class, 'index']);
        Route::put('/providers/{provider}', [ProviderCredentialController::class, 'update'])
            ->where('provider', 'plex|listenbrainz|discogs|gotify|theaudiodb')->middleware('throttle:5,1');
        Route::delete('/providers/{provider}', [ProviderCredentialController::class, 'destroy'])
            ->where('provider', 'plex|listenbrainz|discogs|gotify|theaudiodb')->middleware('throttle:5,1');
        Route::get('/operations', [ManualOperationController::class, 'index']);
        Route::post('/operations/{operation}', [ManualOperationController::class, 'store'])
            ->where('operation', '[a-z.]+')->middleware('throttle:10,1');
    });
    Route::get('/home', HomeController::class);
    Route::get('/home/lenses/{type}', HomeLensController::class)->where('type', '[a-z-]+');
    Route::get('/discover', DiscoverController::class);
    Route::get('/discover/upcoming', UpcomingReleaseController::class);
    Route::get('/notifications', [UpcomingReleaseNotificationController::class, 'index']);
    Route::patch('/notifications/{id}', [UpcomingReleaseNotificationController::class, 'update'])->whereUuid('id')->middleware('throttle:60,1');
    Route::get('/beyond', BeyondLibraryController::class);
    Route::get('/library/albums', LibraryAlbumController::class);
    Route::get('/albums/{id}', AlbumController::class)->whereUuid('id');
    Route::get('/want-to-listen', AlbumListController::class);
    Route::patch('/albums/{id}/list-state', [AlbumListStateController::class, 'update'])->whereUuid('id')->middleware('throttle:30,1');
    Route::delete('/albums/{id}/list-state', [AlbumListStateController::class, 'destroy'])->whereUuid('id')->middleware('throttle:30,1');
    Route::get('/artists', ArtistIndexController::class);
    Route::get('/artists/{id}', ArtistController::class)->whereUuid('id');
    Route::get('/artists/{id}/discography', ArtistDiscographyController::class)->whereUuid('id');
    Route::post('/artists/{id}/discography/refresh', ArtistDiscographyRefreshController::class)->whereUuid('id')->middleware('throttle:5,1');
    Route::put('/artists/{id}/follow', [ArtistFollowController::class, 'update'])->whereUuid('id')->middleware('throttle:30,1');
    Route::delete('/artists/{id}/follow', [ArtistFollowController::class, 'destroy'])->whereUuid('id')->middleware('throttle:30,1');
    Route::get('/search', SearchController::class);
    Route::get('/external-catalog/search', [ExternalCatalogController::class, 'index'])->middleware('throttle:20,1');
    Route::post('/external-catalog/release-groups/{mbid}', [ExternalCatalogController::class, 'store'])
        ->whereUuid('mbid')->middleware('throttle:10,1');
    Route::get('/metadata/coverage', MetadataCoverageController::class);
    Route::get('/metadata/diagnostics', MetadataDiagnosticsController::class);
    Route::post('/metadata/diagnostics/{category}/{id}/retry', RetryMetadataDiagnosticController::class)
        ->where('category', 'artwork|narrative')->whereUuid('id')->middleware('throttle:10,1');
    Route::get('/metadata/pipelines/{pipeline}/diagnostics', PipelineDiagnosticsController::class)
        ->where('pipeline', 'discogs|discographies|discography-artwork');
    Route::post('/metadata/pipelines/{pipeline}/diagnostics/{id}/retry', RetryPipelineDiagnosticController::class)
        ->where('pipeline', 'discogs|discography-artwork')->whereUuid('id')->middleware('throttle:10,1');
    Route::put('/home/editions/{editionId}/recommendations/{entityId}/feedback', [RecommendationFeedbackController::class, 'update'])
        ->whereUuid('editionId')->whereUuid('entityId')->middleware('throttle:30,1');
    Route::delete('/home/editions/{editionId}/recommendations/{entityId}/feedback', [RecommendationFeedbackController::class, 'destroy'])
        ->whereUuid('editionId')->whereUuid('entityId')->middleware('throttle:30,1');
    Route::delete('/recommendation-feedback/{entityId}', [RecommendationFeedbackController::class, 'destroyEntity'])
        ->whereUuid('entityId')->middleware('throttle:30,1');
    Route::put('/recommendations/{itemId}/feedback', [RecommendationFeedbackController::class, 'updateItem'])
        ->whereUuid('itemId')->middleware('throttle:30,1');
    Route::get('/plex/open-target/{plexItem}', PlexOpenTargetController::class)->whereUuid('plexItem');
    Route::post('/playback/sessions', [PlaybackSessionController::class, 'store'])->middleware('throttle:20,1');
    Route::patch('/playback/sessions/{session}', [PlaybackSessionController::class, 'update'])
        ->where('session', '[A-Za-z0-9_-]{43}')->middleware('throttle:120,1');
    Route::delete('/playback/sessions/{session}', [PlaybackSessionController::class, 'destroy'])
        ->where('session', '[A-Za-z0-9_-]{43}')->middleware('throttle:30,1');
    Route::get('/playback/sessions/{session}/stream', PlaybackStreamController::class)
        ->where('session', '[A-Za-z0-9_-]{43}')->middleware('throttle:240,1')->name('api.playback.stream');
    Route::get('/artwork/{artwork}/{checksum}', ArtworkController::class)
        ->whereUuid('artwork')
        ->where('checksum', '[a-f0-9]{64}')
        ->name('api.artwork');
    Route::get('/entity-artwork/{artwork}/{checksum}', EntityArtworkController::class)
        ->whereUuid('artwork')
        ->where('checksum', '[a-f0-9]{64}')
        ->name('api.entity-artwork');
});

Route::view('/{path?}', 'app')
    ->where('path', '^(?!api(?:/|$)|auth(?:/|$)|up$).*$');

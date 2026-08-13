<?php

namespace Tests\Feature;

use App\Music\Descriptions\WikimediaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikimediaClientTest extends TestCase
{
    public function test_it_resolves_an_exact_wikidata_sitelink_and_plain_text_introduction(): void
    {
        config()->set('services.wikimedia.language', 'en');
        Http::fake(function ($request) {
            if (parse_url($request->url(), PHP_URL_HOST) === 'www.wikidata.org') {
                return Http::response([
                    'entities' => ['Q123' => ['sitelinks' => ['enwiki' => ['title' => 'Fixture Album']]]],
                ], 200, ['Content-Type' => 'application/json']);
            }

            return Http::response([
                'query' => ['pages' => [[
                    'title' => 'Fixture Album',
                    'extract' => 'A plain-text album introduction.',
                ]]],
            ], 200, ['Content-Type' => 'application/json']);
        });

        $client = app(WikimediaClient::class);
        $title = $client->titleForWikidata('Q123');
        $introduction = $client->introduction($title);

        $this->assertSame('Fixture Album', $title);
        $this->assertSame('A plain-text album introduction.', $introduction['text']);
        $this->assertSame('https://en.wikipedia.org/wiki/Fixture_Album', $introduction['source_url']);
        Http::assertSentCount(2);
    }
}

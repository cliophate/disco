<?php

namespace App\Music\Descriptions;

use App\Music\Http\BoundedResponseBody;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class WikimediaClient
{
    /** @return array{text:string,language:string,source_url:string,external_id:string}|null */
    public function introduction(string $title, ?string $language = null): ?array
    {
        $language = $this->language($language);
        $title = trim(str_replace('_', ' ', $title));
        if ($title === '' || strlen($title) > 255 || preg_match('/[\x00-\x1F\x7F]/', $title)) {
            throw new RuntimeException('Invalid Wikipedia page title.');
        }
        $payload = $this->get("https://{$language}.wikipedia.org/w/api.php", [
            'action' => 'query',
            'prop' => 'extracts',
            'exintro' => 1,
            'explaintext' => 1,
            'redirects' => 1,
            'titles' => $title,
            'format' => 'json',
            'formatversion' => 2,
        ]);
        $page = data_get($payload, 'query.pages.0');
        $text = is_array($page) && is_string($page['extract'] ?? null) ? trim($page['extract']) : '';
        if ($text === '') {
            return null;
        }
        $resolvedTitle = is_string($page['title'] ?? null) ? $page['title'] : $title;

        return [
            'text' => $text,
            'language' => $language,
            'source_url' => "https://{$language}.wikipedia.org/wiki/".rawurlencode(str_replace(' ', '_', $resolvedTitle)),
            'external_id' => $resolvedTitle,
        ];
    }

    public function titleForWikidata(string $qid, ?string $language = null): ?string
    {
        $language = $this->language($language);
        $qid = strtoupper($qid);
        if (preg_match('/\AQ[1-9][0-9]*\z/', $qid) !== 1) {
            throw new RuntimeException('Invalid Wikidata identity.');
        }
        $payload = $this->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetentities',
            'ids' => $qid,
            'props' => 'sitelinks',
            'sitefilter' => "{$language}wiki",
            'format' => 'json',
        ]);
        $title = data_get($payload, "entities.{$qid}.sitelinks.{$language}wiki.title");

        return is_string($title) && $title !== '' ? $title : null;
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query): array
    {
        $timeout = (int) config('services.wikimedia.timeout', 30);
        [$response, $body] = retry(3, function () use ($query, $timeout, $url): array {
            $response = Http::acceptJson()
                ->withUserAgent((string) config('services.wikimedia.user_agent'))
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => $timeout])
                ->connectTimeout(10)
                ->timeout($timeout)
                ->get($url, $query);
            if (in_array($response->status(), [429, 503], true)) {
                $response->toPsrResponse()->getBody()->close();
                $response->throw();
            }
            $body = BoundedResponseBody::read($response, 2 * 1024 * 1024, 'Wikimedia returned an invalid response.', $timeout);
            $response->throw();

            return [$response, $body];
        }, 1000, fn (?Throwable $exception): bool => $exception instanceof RequestException
            && in_array($exception->response->status(), [429, 503], true));
        if (! str_contains(strtolower($response->header('Content-Type', '')), 'json')) {
            throw new RuntimeException('Wikimedia returned an invalid response.');
        }
        $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('Wikimedia returned malformed JSON.');
        }

        return $payload;
    }

    private function language(?string $language): string
    {
        $language ??= (string) config('services.wikimedia.language', 'en');
        $language = strtolower($language);
        if (preg_match('/\A[a-z]{2,3}\z/', $language) !== 1) {
            throw new RuntimeException('WIKIMEDIA_LANGUAGE must be a language subdomain.');
        }

        return $language;
    }
}

<?php

namespace App\Music\Notifications;

use App\Models\UpcomingReleaseNotification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GotifyClient
{
    public function __construct(private readonly ?string $token = null) {}

    public function configured(): bool
    {
        return filled(config('services.gotify.url')) && $this->resolvedToken(false) !== '';
    }

    public function send(UpcomingReleaseNotification $notification): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Gotify delivery is not configured.');
        }
        $albumUrl = rtrim((string) config('app.url'), '/')."/albums/{$notification->release_group_id}";

        return $this->post([
            'title' => $this->text("New release from {$notification->artist_credit_name}", 160),
            'message' => $this->text("{$notification->title} arrives {$notification->release_date->format('j M Y')}.\n\nOpen in Disco: {$albumUrl}", 1000),
            'priority' => (int) config('services.gotify.priority', 5),
            'extras' => [
                'client::display' => ['contentType' => 'text/markdown'],
                'client::notification' => ['click' => ['url' => $albumUrl]],
            ],
        ]);
    }

    public function sendTestMessage(): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Gotify delivery is not configured.');
        }

        return $this->post([
            'title' => 'Disco credential test',
            'message' => 'The Gotify provider credential was verified.',
            'priority' => -2,
        ]);
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.gotify.url'), '/');
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Gotify requires an HTTPS origin without credentials, ports, paths, queries, or fragments.');
        }

        return $url;
    }

    private function text(string $value, int $limit): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? ''), 0, $limit);
    }

    /** @param array<string, mixed> $message */
    private function post(array $message): string
    {
        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Gotify-Key' => $this->resolvedToken()])
            ->withOptions(['allow_redirects' => false])
            ->timeout((int) config('services.gotify.timeout', 10))
            ->post('/message', $message)
            ->throw();

        $id = $response->json('id');
        if (! is_int($id) && ! is_string($id)) {
            throw new RuntimeException('Gotify returned no message identity.');
        }

        return (string) $id;
    }

    private function resolvedToken(bool $required = true): string
    {
        $token = $this->token ?? (string) config('services.gotify.token');
        if ($required && $token === '') {
            throw new RuntimeException('GOTIFY_TOKEN is not configured.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new RuntimeException('GOTIFY_TOKEN is invalid.');
        }

        return $token;
    }
}

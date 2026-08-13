<?php

namespace App\Music\Admin;

use App\Models\ProviderCredential;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ProviderCredentialResolver
{
    /** @var array<string, string> */
    private const CONFIG_KEYS = [
        'plex' => 'token',
        'listenbrainz' => 'token',
        'discogs' => 'token',
        'gotify' => 'token',
        'theaudiodb' => 'api_key',
    ];

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys(self::CONFIG_KEYS);
    }

    public function supports(string $provider): bool
    {
        return isset(self::CONFIG_KEYS[$provider]);
    }

    /** @return array{provider:string,secret:?string,configured:bool,source:string,tested_at:?CarbonInterface} */
    public function resolve(string $provider): array
    {
        if (! $this->supports($provider)) {
            throw new InvalidArgumentException("Unsupported credential provider [{$provider}].");
        }

        $credential = $this->databaseCredential($provider);
        if ($credential !== null) {
            $credentials = $credential->credentials;
            if (! is_array($credentials)) {
                throw new DecryptException('Stored provider credential is unreadable.');
            }
            $secret = $credentials[self::CONFIG_KEYS[$provider]] ?? null;
            $secret = is_string($secret) && $secret !== '' ? $secret : null;

            return [
                'provider' => $provider,
                'secret' => $secret,
                'configured' => $secret !== null,
                'source' => 'database',
                'tested_at' => $credential->tested_at,
            ];
        }

        $secret = config("services.{$provider}.".self::CONFIG_KEYS[$provider]);
        $secret = is_string($secret) && $secret !== '' ? $secret : null;

        return [
            'provider' => $provider,
            'secret' => $secret,
            'configured' => $secret !== null,
            'source' => $secret === null ? 'missing' : 'environment',
            'tested_at' => null,
        ];
    }

    /** @return array{provider:string,configured:bool,source:string,tested_at:?CarbonInterface} */
    public function status(string $provider): array
    {
        try {
            $resolved = $this->resolve($provider);
        } catch (DecryptException) {
            return [
                'provider' => $provider,
                'configured' => false,
                'source' => 'unreadable',
                'tested_at' => null,
            ];
        }
        unset($resolved['secret']);

        return $resolved;
    }

    /** @return list<array{provider:string,configured:bool,source:string,tested_at:?CarbonInterface}> */
    public function statuses(): array
    {
        return array_map(fn (string $provider): array => $this->status($provider), $this->providers());
    }

    private function databaseCredential(string $provider): ?ProviderCredential
    {
        $connection = (string) config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        if (! is_string($driver) || ! in_array($driver, \PDO::getAvailableDrivers(), true)) {
            return null;
        }

        try {
            if (! Schema::hasTable('app.provider_credentials')) {
                return null;
            }

            return ProviderCredential::query()->find($provider);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '42P01'
                || (str_contains(strtolower($exception->getMessage()), 'no such table')
                    && str_contains($exception->getMessage(), 'provider_credentials'))) {
                return null;
            }

            throw $exception;
        }
    }
}

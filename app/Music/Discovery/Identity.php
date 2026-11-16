<?php

namespace App\Music\Discovery;

use Illuminate\Support\Str;

class Identity
{
    public static function name(?string $value): string
    {
        $value = str_replace(['’', '‘'], "'", $value ?? '');

        return Str::lower(trim(preg_replace('/\s+/u', ' ', (class_exists(\Normalizer::class) ? \Normalizer::normalize($value, \Normalizer::FORM_C) : $value)) ?? $value));
    }

    public static function album(string $artist, string $title): string
    {
        return json_encode([self::name($artist), self::name($title)], JSON_THROW_ON_ERROR);
    }

    public static function mbids(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])->filter(fn ($value) => is_string($value) && Str::isUuid($value))
            ->map(fn (string $value) => strtolower($value))->unique()->values()->all();
    }
}

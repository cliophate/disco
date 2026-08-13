<?php

namespace App\Http\Presenters;

class ArtistNamePresenter
{
    /** @return array{name:string,credited_name:?string} */
    public function present(string $creditedName, ?string $type, ?string $disambiguation): array
    {
        $creditedName = trim($creditedName);
        $candidate = trim((string) $disambiguation);
        if (strtolower((string) $type) !== 'person'
            || ! $this->isSymbolDominant($creditedName)
            || ! preg_match("/^\\p{Lu}[\\p{L}\\p{M}'’.-]+(?:\\s+\\p{Lu}[\\p{L}\\p{M}'’.-]+)+$/u", $candidate)) {
            return ['name' => $creditedName, 'credited_name' => null];
        }

        return ['name' => $candidate, 'credited_name' => $creditedName];
    }

    private function isSymbolDominant(string $value): bool
    {
        $characters = preg_match_all('/./us', $value);
        $letters = preg_match_all('/[\p{L}\p{N}]/u', $value);
        $marks = preg_match_all('/\p{M}/u', $value);

        return $characters !== false && $letters !== false && $marks !== false
            && ($letters * 2 < $characters || $marks >= 6);
    }
}

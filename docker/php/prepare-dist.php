<?php

declare(strict_types=1);

$path = $argv[1] ?? 'composer.lock';
$lock = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
foreach (['packages', 'packages-dev'] as $group) {
    foreach ($lock[$group] ?? [] as $index => $package) {
        $dist = $package['dist'] ?? [];
        if (($dist['type'] ?? '') === 'zip'
            && preg_match('~\Ahttps://api\.github\.com/repos/([^/]+/[^/]+)/zipball/([a-f0-9]{40})\z~D', $dist['url'] ?? '', $match) === 1
            && ($dist['reference'] ?? '') === $match[2]) {
            $lock[$group][$index]['dist']['url'] = "https://codeload.github.com/{$match[1]}/legacy.zip/{$match[2]}";
        }
    }
}
file_put_contents($path, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

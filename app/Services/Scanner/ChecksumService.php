<?php

namespace App\Services\Scanner;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ChecksumService
{
    public function coreChecksums(string $version, string $locale = 'en_US'): array
    {
        return Cache::remember("scanner:core-checksums:{$version}:{$locale}", 86400, function () use ($version, $locale) {
            try {
                $res = Http::timeout(10)->get('https://api.wordpress.org/core/checksums/1.0/', [
                    'version' => $version, 'locale' => $locale,
                ]);
                return $res->ok() ? ($res->json('checksums') ?? []) : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function needsContent(array $manifest, array $coreChecksums, array $previousManifest): array
    {
        $needed = [];
        foreach ($manifest as $entry) {
            $path = $entry['path'];
            $md5 = $entry['md5'];
            if (isset($coreChecksums[$path]) && hash_equals($coreChecksums[$path], $md5)) {
                continue; // verified core file
            }
            if (isset($previousManifest[$path]) && hash_equals($previousManifest[$path], $md5)) {
                continue; // unchanged since last scan
            }
            $needed[] = $path;
        }
        return $needed;
    }
}

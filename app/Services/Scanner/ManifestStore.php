<?php

namespace App\Services\Scanner;

use Illuminate\Support\Facades\Storage;

class ManifestStore
{
    public function load(int $projectId): array
    {
        $path = $this->path($projectId);
        if (!Storage::disk('local')->exists($path)) {
            return [];
        }
        return json_decode(Storage::disk('local')->get($path), true) ?: [];
    }

    public function save(int $projectId, array $manifest): void
    {
        $map = [];
        foreach ($manifest as $entry) {
            $map[$entry['path']] = $entry['md5'];
        }
        Storage::disk('local')->put($this->path($projectId), json_encode($map));
    }

    private function path(int $projectId): string
    {
        return "scanner/manifests/{$projectId}.json";
    }
}

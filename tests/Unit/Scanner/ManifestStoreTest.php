<?php

use App\Services\Scanner\ManifestStore;
use Illuminate\Support\Facades\Storage;

it('saves and loads a per-project manifest', function () {
    Storage::fake('local');
    $store = new ManifestStore();
    $store->save(99, [['path' => 'a.php', 'md5' => 'h1'], ['path' => 'b.php', 'md5' => 'h2']]);
    expect($store->load(99))->toBe(['a.php' => 'h1', 'b.php' => 'h2']);
});

it('returns an empty manifest when none exists', function () {
    Storage::fake('local');
    expect((new ManifestStore())->load(1234))->toBe([]);
});

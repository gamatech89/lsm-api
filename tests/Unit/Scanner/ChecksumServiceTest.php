<?php

use App\Services\Scanner\ChecksumService;
use Illuminate\Support\Facades\Http;

it('caches core checksums fetched from wp.org', function () {
    Http::fake(['api.wordpress.org/*' => Http::response(['checksums' => ['wp-load.php' => 'abc123']], 200)]);
    $svc = new ChecksumService();
    expect($svc->coreChecksums('6.5', 'en_US'))->toBe(['wp-load.php' => 'abc123']);
    // Second call must hit cache, not HTTP.
    Http::fake(['api.wordpress.org/*' => Http::response([], 500)]);
    expect($svc->coreChecksums('6.5', 'en_US'))->toBe(['wp-load.php' => 'abc123']);
});

it('needs content only for changed/unknown paths', function () {
    $svc = new ChecksumService();
    $manifest = [
        ['path' => 'wp-load.php', 'md5' => 'core-hash', 'size' => 10],       // matches core -> skip
        ['path' => 'wp-content/themes/t/f.php', 'md5' => 'same', 'size' => 5], // matches previous -> skip
        ['path' => 'wp-content/uploads/x.php', 'md5' => 'new', 'size' => 7],   // unknown -> needed
    ];
    $needed = $svc->needsContent($manifest, ['wp-load.php' => 'core-hash'], ['wp-content/themes/t/f.php' => 'same']);
    expect($needed)->toBe(['wp-content/uploads/x.php']);
});

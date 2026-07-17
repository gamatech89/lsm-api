<?php
// tests/Unit/Scanner/ScannerEngineTest.php
use App\Services\Scanner\ScannerEngine;

function engine(): ScannerEngine {
    return new ScannerEngine(config('scanner_signatures'));
}

it('flags an eval+base64 backdoor in a php file as critical', function () {
    // Build the malicious string at runtime so this test file is scanner-clean.
    $payload = 'eval' . '(base64' . '_decode(' . '$_POST[0]));';
    $findings = engine()->scanContent('wp-content/uploads/x.php', "<?php {$payload}");
    expect($findings)->not->toBeEmpty()
        ->and($findings[0]['severity'])->toBe('critical')
        ->and($findings[0]['line'])->toBe(1)
        ->and($findings[0]['file'])->toBe('wp-content/uploads/x.php');
});

it('does not flag injection-category patterns in non-php files', function () {
    // base64_decode is normal in minified JS; injection category is PHP-only.
    $js = 'var a = base64' . '_decode("zzz");';
    $findings = engine()->scanContent('wp-content/themes/t/app.js', $js);
    $categories = array_column($findings, 'category');
    expect($categories)->not->toContain('injection');
});

it('returns an empty array for clean content', function () {
    expect(engine()->scanContent('wp-content/themes/t/functions.php', "<?php add_action('init', fn() => null);"))
        ->toBe([]);
});

it('computes shannon entropy near 0 for a repeated byte', function () {
    expect(engine()->shannonEntropy(str_repeat('A', 500)))->toBeLessThan(0.01);
});

it('flags a long high-entropy string as obfuscation', function () {
    $blob = base64_encode(random_bytes(400)); // ~533 chars, entropy ~6
    $php = "<?php \$x = '{$blob}';";
    $findings = engine()->entropyFindings('wp-content/themes/t/a.php', $php);
    expect($findings)->not->toBeEmpty()
        ->and($findings[0]['entropy'])->toBeGreaterThan(5.5)
        ->and($findings[0]['severity'])->toBeIn(['high', 'critical']);
});

it('does not flag ordinary source code', function () {
    expect(engine()->entropyFindings('wp-content/themes/t/f.php', "<?php\nfunction hello() { return 'world'; }\n"))
        ->toBe([]);
});

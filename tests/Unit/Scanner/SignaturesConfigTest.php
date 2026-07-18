<?php
// tests/Unit/Scanner/SignaturesConfigTest.php

it('exposes signature config with required top-level keys', function () {
    $cfg = config('scanner_signatures');
    expect($cfg)->toBeArray()
        ->and($cfg)->toHaveKeys([
            'version', 'string_patterns', 'regex_patterns', 'spam_keywords',
            'suspicious_option_patterns', 'suspicious_cron_regexes',
            'fake_plugin_patterns', 'htaccess_patterns',
        ]);
});

it('has plaintext backdoor signatures (no base64 encoding)', function () {
    $cfg = config('scanner_signatures');
    expect($cfg['string_patterns'])->toHaveKey('backdoor')
        ->and($cfg['string_patterns']['backdoor'])->toHaveKey('eval(base64_decode(');
});

it('provides htaccess patterns as valid regexes', function () {
    foreach (config('scanner_signatures')['htaccess_patterns'] as $p) {
        expect(@preg_match($p['pattern'], ''))->not->toBeFalse("Invalid regex: {$p['pattern']}");
    }
});

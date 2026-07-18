<?php

use App\Services\Scanner\ScanSession;

it('creates and reloads a session by token', function () {
    $s = ScanSession::create(projectId: 1, scanId: 42, scanType: 'full');
    expect($s->token())->toBeString()->and(strlen($s->token()))->toBeGreaterThanOrEqual(32);
    $again = ScanSession::load($s->token());
    expect($again->scanId())->toBe(42)->and($again->projectId())->toBe(1);
});

it('accumulates findings and assembles the frozen results shape', function () {
    $s = ScanSession::create(1, 42, 'full');
    $s->addFindings('malware_signatures', [
        ['file' => 'x.php', 'severity' => 'critical', 'description' => 'd'],
    ]);
    $s->addFindings('permissions', [
        ['file' => 'wp-config.php', 'severity' => 'medium', 'reason' => 'r'],
    ]);
    $s->incrementFilesScanned(120);

    $out = $s->assembleResults();
    expect($out)->toHaveKeys(['scan_id', 'status', 'summary', 'results'])
        ->and($out['summary']['threats_found'])->toBe(1)   // critical
        ->and($out['summary']['warnings_found'])->toBe(1)  // medium
        ->and($out['summary']['total_files_scanned'])->toBe(120)
        ->and($out['summary']['clean'])->toBeFalse()
        ->and($out['results'])->toHaveKey('malware_signatures');
});

it('reports clean when there are no threats', function () {
    $s = ScanSession::create(1, 7, 'quick');
    expect($s->assembleResults()['summary']['clean'])->toBeTrue()
        ->and($s->assembleResults()['summary']['risk_level'])->toBe('clean');
});

it('does not count info-severity findings as warnings or fail a module', function () {
    $s = ScanSession::create(1, 99, 'full');
    $s->addFindings('suspicious_files', [
        ['file' => 'plugins/akismet', 'severity' => 'info', 'type' => 'plugin_dir'],
        ['file' => 'plugins/woocommerce', 'severity' => 'info', 'type' => 'plugin_dir'],
        ['file' => 'plugins/yoast-seo', 'severity' => 'info', 'type' => 'plugin_dir'],
    ]);

    $out = $s->assembleResults();

    expect($out['summary']['warnings_found'])->toBe(0)
        ->and($out['summary']['threats_found'])->toBe(0)
        ->and($out['summary']['clean'])->toBeTrue()
        ->and($out['summary']['risk_level'])->toBe('clean')
        ->and($out['results']['suspicious_files']['status'])->toBe('pass')
        ->and($out['results']['suspicious_files']['findings'])->toHaveCount(3);
});

it('never lets info findings inflate warnings even alongside real threats', function () {
    $s = ScanSession::create(1, 100, 'full');
    $s->addFindings('malware_signatures', [
        ['file' => 'evil.php', 'severity' => 'critical', 'description' => 'd'],
    ]);
    $s->addFindings('suspicious_files', [
        ['file' => 'plugins/one', 'severity' => 'info', 'type' => 'plugin_dir'],
        ['file' => 'plugins/two', 'severity' => 'info', 'type' => 'plugin_dir'],
        ['file' => 'plugins/three', 'severity' => 'info', 'type' => 'plugin_dir'],
        ['file' => 'plugins/four', 'severity' => 'info', 'type' => 'plugin_dir'],
        ['file' => 'plugins/five', 'severity' => 'info', 'type' => 'plugin_dir'],
        ['file' => 'plugins/six', 'severity' => 'info', 'type' => 'plugin_dir'],
    ]);

    $out = $s->assembleResults();

    expect($out['summary']['threats_found'])->toBe(1)
        ->and($out['summary']['warnings_found'])->toBe(0)
        ->and($out['summary']['risk_level'])->toBe('medium');
});

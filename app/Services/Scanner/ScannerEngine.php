<?php
// app/Services/Scanner/ScannerEngine.php
namespace App\Services\Scanner;

class ScannerEngine
{
    private const PHP_EXTENSIONS = ['php', 'phtml', 'php5', 'php7', 'inc'];

    public function __construct(private array $signatures) {}

    public function scanContent(string $relativePath, string $content): array
    {
        $findings = [];
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $isPhp = in_array($ext, self::PHP_EXTENSIONS, true);

        foreach ($this->signatures['string_patterns'] ?? [] as $category => $patterns) {
            if (($category === 'injection' || $category === 'weak_indicator') && !$isPhp) {
                continue;
            }
            foreach ($patterns as $pattern => $description) {
                $pos = stripos($content, $pattern);
                if ($pos !== false) {
                    $findings[] = [
                        'file' => $relativePath,
                        'line' => substr_count(substr($content, 0, $pos), "\n") + 1,
                        'pattern' => $pattern,
                        'description' => $description,
                        'severity' => $this->severityForCategory($category),
                        'category' => $category,
                        'snippet' => $this->snippetAt($content, $pos),
                    ];
                }
            }
        }

        foreach ($this->signatures['regex_patterns'] ?? [] as $rp) {
            if (preg_match($rp['pattern'], $content, $m, PREG_OFFSET_CAPTURE)) {
                $offset = $m[0][1];
                $findings[] = [
                    'file' => $relativePath,
                    'line' => substr_count(substr($content, 0, $offset), "\n") + 1,
                    'pattern' => substr($m[0][0], 0, 100),
                    'description' => $rp['description'],
                    'severity' => $rp['severity'],
                    'category' => $rp['category'],
                    'snippet' => $this->snippetAt($content, $offset),
                ];
            }
        }

        return $findings;
    }

    public function severityForCategory(string $category): string
    {
        return match ($category) {
            'backdoor', 'shell', 'known_malware' => 'critical',
            'file_operation', 'injection', 'data_theft', 'seo_spam' => 'high',
            'obfuscation' => 'medium',
            'weak_indicator' => 'info',
            default => 'low',
        };
    }

    public function entropyFindings(string $relativePath, string $content): array
    {
        // Minified assets (.min.js/.min.css) are legitimately dense and single-lined;
        // running entropy heuristics on them only produces false positives.
        if (preg_match('/\.min\.(js|css)$/i', $relativePath)) {
            return [];
        }

        // Targeted check: a long high-entropy quoted string is the classic
        // eval("<base64 payload>") shape — keep this at high/critical.
        if (preg_match_all('/[\'"][^\'"\n]{200,}[\'"]/s', $content, $m)) {
            foreach ($m[0] as $long) {
                $entropy = $this->shannonEntropy($long);
                if ($entropy > 5.8) {
                    return [[
                        'file' => $relativePath,
                        'description' => sprintf('High-entropy string detected (entropy: %.2f) — likely obfuscated payload', $entropy),
                        'severity' => $entropy > 6.2 ? 'critical' : 'high',
                        'entropy' => round($entropy, 2),
                        'string_length' => strlen($long),
                        'preview' => substr($long, 0, 80) . '...',
                    ]];
                }
            }
        }

        // Weaker signal: a very long high-entropy line. Legitimate files carry these
        // too (minified bundles, theme block-patterns with embedded base64 images), so
        // require a higher entropy floor and report as a warning (medium), not a threat.
        foreach (explode("\n", $content) as $i => $line) {
            if (strlen($line) > 1000) {
                $entropy = $this->shannonEntropy($line);
                if ($entropy > 5.5) {
                    return [[
                        'file' => $relativePath,
                        'line' => $i + 1,
                        'description' => sprintf('Very long line (%d chars) with high entropy (%.2f) — potential obfuscated code', strlen($line), $entropy),
                        'severity' => 'medium',
                        'entropy' => round($entropy, 2),
                    ]];
                }
            }
        }

        return [];
    }

    public function shannonEntropy(string $data): float
    {
        $len = strlen($data);
        if ($len === 0) return 0.0;
        $freq = count_chars($data, 1);
        $entropy = 0.0;
        foreach ($freq as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }
        return $entropy;
    }

    private function snippetAt(string $content, int $pos): string
    {
        $start = max(0, $pos - 50);
        $length = min(strlen($content) - $start, 200);
        $snippet = trim(preg_replace('/\s+/', ' ', substr($content, $start, $length)));
        if ($start > 0) $snippet = '...' . $snippet;
        if ($start + $length < strlen($content)) $snippet .= '...';
        return $snippet;
    }

    public function scanHtaccess(string $relativePath, string $content, string $homeHost): array
    {
        $findings = [];
        foreach ($this->signatures['htaccess_patterns'] as $p) {
            if (preg_match($p['pattern'], $content, $m)) {
                $findings[] = [
                    'file' => $relativePath,
                    'line' => substr_count(substr($content, 0, (int) strpos($content, $m[0])), "\n") + 1,
                    'description' => $p['description'],
                    'severity' => $p['severity'],
                    'match' => substr($m[0], 0, 150),
                ];
            }
        }
        $allow = preg_quote($homeHost, '/') . '|googleapis|google|facebook|twitter|youtube';
        if (preg_match('/RewriteRule.*https?:\/\/(?!.*(' . $allow . '))/i', $content, $m)) {
            $findings[] = [
                'file' => $relativePath,
                'line' => substr_count(substr($content, 0, (int) strpos($content, $m[0])), "\n") + 1,
                'description' => 'External redirect to unknown domain',
                'severity' => 'high',
                'match' => substr($m[0], 0, 150),
            ];
        }
        return $findings;
    }

    public function analyzeDatabase(array $c): array
    {
        $f = [];

        // Admin anomalies
        $regTimes = [];
        foreach ($c['admins'] ?? [] as $a) {
            $regTimes[] = $a['registered'] ?? '';
            if (empty($a['email'])) {
                $f[] = ['type' => 'admin_no_email', 'severity' => 'critical',
                    'description' => sprintf('Admin user "%s" (ID: %s) has NO email address — strong malware indicator', $a['login'], $a['id'])];
            }
            if (preg_match('/^(sys_|tmp[-_]|devuser|admin[0-9]{3,}|[a-f0-9]{8,})/', $a['login'] ?? '')) {
                $f[] = ['type' => 'suspicious_admin_username', 'severity' => 'high',
                    'description' => sprintf('Admin user "%s" has suspicious username pattern', $a['login'])];
            }
        }
        foreach (array_count_values(array_filter($regTimes)) as $time => $n) {
            if ($n >= 3) {
                $f[] = ['type' => 'batch_admin_creation', 'severity' => 'critical',
                    'description' => sprintf('%d admin accounts created at exact same time (%s) — automated malware', $n, $time)];
            }
        }

        // Suspicious crons
        foreach ($c['crons'] ?? [] as $hook) {
            foreach ($this->signatures['suspicious_cron_regexes'] as $re) {
                if (preg_match($re, $hook) || str_contains($hook, 'eval') || str_contains($hook, 'base64')) {
                    $f[] = ['type' => 'suspicious_cron', 'severity' => 'high',
                        'description' => sprintf('Suspicious cron job: "%s"', $hook)];
                    break;
                }
            }
        }

        // Code-snippet plugin injections
        foreach ($c['code_snippets'] ?? [] as $s) {
            foreach ($this->scanContent(($s['source'] ?? 'snippet') . '.php', (string) ($s['code'] ?? '')) as $hit) {
                $f[] = ['type' => 'malicious_' . ($s['source'] ?? 'code') . '_snippet', 'severity' => 'critical',
                    'description' => sprintf('%s snippet "%s" contains suspicious code', $s['source'] ?? 'Code', $s['title'] ?? ''),
                    'details' => ['preview' => substr((string) ($s['code'] ?? ''), 0, 200)]];
                break;
            }
        }

        // Suspicious options
        foreach ($c['options'] ?? [] as $opt) {
            $f[] = ['type' => 'suspicious_option', 'severity' => 'high',
                'description' => sprintf('Suspicious option found: "%s"', $opt['name'] ?? ''),
                'details' => ['value_preview' => $opt['preview'] ?? '']];
        }

        // Posts / SEO spam
        $posts = $c['posts'] ?? [];
        foreach ($posts['mass_days'] ?? [] as $d) {
            $f[] = ['type' => 'mass_published_posts', 'severity' => 'critical',
                'description' => sprintf('%s posts published on %s — likely SEO spam injection', number_format((int) $d['count']), $d['date'])];
        }
        if (($posts['spam_keyword_count'] ?? 0) > 5) {
            $f[] = ['type' => 'spam_keyword_posts', 'severity' => ($posts['spam_keyword_count'] > 100 ? 'critical' : 'high'),
                'description' => sprintf('%s posts with spam keywords detected', number_format((int) $posts['spam_keyword_count'])),
                'details' => ['sample_titles' => substr((string) ($posts['spam_sample'] ?? ''), 0, 200)]];
        }

        // DB-level persistence
        foreach (['triggers' => 'database_trigger', 'events' => 'database_event', 'routines' => 'database_routine'] as $key => $type) {
            foreach ($c['db_objects'][$key] ?? [] as $obj) {
                $stmt = (string) ($obj['statement'] ?? $obj['definition'] ?? '');
                $sev = preg_match('/eval|base64|exec|system|curl|file_put_contents/i', $stmt) ? 'critical' : 'high';
                $f[] = ['type' => $type, 'severity' => $sev,
                    'description' => sprintf('Database %s found: "%s" — unusual in WordPress', str_replace('database_', '', $type), $obj['name'] ?? '')];
            }
        }

        // siteurl / home integrity
        $s = $c['siteurl'] ?? [];
        if (!empty($s['config_siteurl']) && ($s['db_siteurl'] ?? null) !== $s['config_siteurl']) {
            $f[] = ['type' => 'siteurl_mismatch', 'severity' => 'critical',
                'description' => sprintf('Site URL mismatch: DB "%s" vs wp-config "%s"', $s['db_siteurl'] ?? '', $s['config_siteurl'])];
        }
        if (!empty($s['config_home']) && ($s['db_home'] ?? null) !== $s['config_home']) {
            $f[] = ['type' => 'home_mismatch', 'severity' => 'critical',
                'description' => sprintf('Home URL mismatch: DB "%s" vs wp-config "%s"', $s['db_home'] ?? '', $s['config_home'])];
        }

        // Fake plugin directories
        foreach ($c['plugins'] ?? [] as $p) {
            foreach ($this->signatures['fake_plugin_patterns'] as $re) {
                if (preg_match($re, $p['dir'] ?? '') && empty($p['has_readme']) && empty($p['has_header'])) {
                    $f[] = ['type' => 'fake_plugin', 'severity' => 'high',
                        'description' => sprintf('Suspicious plugin directory "%s" matches known fake plugin pattern', $p['dir'])];
                    break;
                }
            }
        }

        return $f;
    }
}

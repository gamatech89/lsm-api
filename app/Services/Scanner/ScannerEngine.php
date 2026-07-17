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
            if ($category === 'injection' && !$isPhp) {
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
            default => 'low',
        };
    }

    public function entropyFindings(string $relativePath, string $content): array
    {
        if (preg_match_all('/[\'"][^\'"\n]{200,}[\'"]/s', $content, $m)) {
            foreach ($m[0] as $long) {
                $entropy = $this->shannonEntropy($long);
                if ($entropy > 5.5) {
                    return [[
                        'file' => $relativePath,
                        'description' => sprintf('High-entropy string detected (entropy: %.2f) — likely obfuscated payload', $entropy),
                        'severity' => $entropy > 6.0 ? 'critical' : 'high',
                        'entropy' => round($entropy, 2),
                        'string_length' => strlen($long),
                        'preview' => substr($long, 0, 80) . '...',
                    ]];
                }
            }
        }

        foreach (explode("\n", $content) as $i => $line) {
            if (strlen($line) > 1000) {
                $entropy = $this->shannonEntropy($line);
                if ($entropy > 5.0) {
                    return [[
                        'file' => $relativePath,
                        'line' => $i + 1,
                        'description' => sprintf('Very long line (%d chars) with high entropy (%.2f) — potential obfuscated code', strlen($line), $entropy),
                        'severity' => 'high',
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
}

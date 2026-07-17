<?php

namespace App\Services\Scanner;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ScanSession
{
    private const TTL_SECONDS = 1800;

    private function __construct(private string $token, private array $state) {}

    public static function create(int $projectId, int $scanId, string $scanType): self
    {
        $token = Str::random(48);
        $state = [
            'project_id' => $projectId,
            'scan_id' => $scanId,
            'scan_type' => $scanType,
            'started_at' => now()->toIso8601String(),
            'files_scanned' => 0,
            'needed_paths' => [],
            'modules' => [],
        ];
        Cache::put(self::key($token), $state, self::TTL_SECONDS);
        return new self($token, $state);
    }

    public static function load(string $token): ?self
    {
        $state = Cache::get(self::key($token));
        return $state ? new self($token, $state) : null;
    }

    public function token(): string { return $this->token; }
    public function scanId(): int { return $this->state['scan_id']; }
    public function projectId(): int { return $this->state['project_id']; }
    public function scanType(): string { return $this->state['scan_type']; }
    public function neededPaths(): array { return $this->state['needed_paths']; }

    public function setNeededPaths(array $paths): void
    {
        $this->state['needed_paths'] = array_values($paths);
        $this->persist();
    }

    public function incrementFilesScanned(int $n): void
    {
        $this->state['files_scanned'] += $n;
        $this->persist();
    }

    public function addFindings(string $module, array $findings): void
    {
        $this->state['modules'][$module] ??= [];
        array_push($this->state['modules'][$module], ...$findings);
        $this->persist();
    }

    public function assembleResults(): array
    {
        $threats = 0; $warnings = 0; $results = [];
        foreach ($this->state['modules'] as $module => $findings) {
            $status = 'pass';
            foreach ($findings as $fnd) {
                $sev = $fnd['severity'] ?? 'low';
                if (in_array($sev, ['critical', 'high'], true)) { $threats++; $status = 'fail'; }
                elseif ($sev !== 'info') { $warnings++; if ($status === 'pass') $status = 'warning'; }
            }
            $results[$module] = ['status' => $status, 'findings' => $findings];
        }

        return [
            'scan_id' => (string) $this->state['scan_id'],
            'started_at' => $this->state['started_at'],
            'completed_at' => now()->toIso8601String(),
            'duration_seconds' => round(now()->getTimestamp() - strtotime($this->state['started_at']), 2),
            'status' => 'completed',
            'summary' => [
                'total_files_scanned' => $this->state['files_scanned'],
                'threats_found' => $threats,
                'warnings_found' => $warnings,
                'clean' => $threats === 0,
                'risk_level' => $this->riskLevel($threats, $warnings),
            ],
            'results' => $results,
        ];
    }

    public function forget(): void { Cache::forget(self::key($this->token)); }

    private function riskLevel(int $threats, int $warnings): string
    {
        return match (true) {
            $threats >= 5 => 'critical',
            $threats >= 2 => 'high',
            $threats >= 1 => 'medium',
            $warnings >= 5 => 'low',
            default => 'clean',
        };
    }

    private function persist(): void { Cache::put(self::key($this->token), $this->state, self::TTL_SECONDS); }
    private static function key(string $token): string { return "scanner:session:{$token}"; }
}

<?php

namespace App\Services;

/**
 * Turns a raw LsmService::hardeningRequest() result into the API's response
 * contract for the .htaccess hardening endpoints.
 *
 * Shared by HardeningController (HTTP responses) and the
 * hardening:resume-expired command (which only needs `outcome` and `status`).
 */
class HardeningResponseMapper
{
    public const MIN_PLUGIN_VERSION = '2.10.0';

    /**
     * outcome: ok | busy | failed | plugin_outdated | unauthorized | unreachable
     *
     * @param  array{http: int|null, json: array|null}  $result
     * @return array{outcome: string, code: int, body: array}
     */
    public static function map(array $result): array
    {
        $http = $result['http'] ?? null;
        $json = $result['json'] ?? null;

        if ($http === 404) {
            return [
                'outcome' => 'plugin_outdated',
                'code' => 409,
                'body' => [
                    'success' => false,
                    'reason' => 'plugin_outdated',
                    'min_version' => self::MIN_PLUGIN_VERSION,
                    'message' => 'Requires plugin '.self::MIN_PLUGIN_VERSION.' or newer — update the plugin',
                ],
            ];
        }

        if ($http === 401 || $http === 403) {
            return [
                'outcome' => 'unauthorized',
                'code' => 502,
                'body' => [
                    'success' => false,
                    'reason' => 'unauthorized',
                    'message' => 'The site rejected the platform API key',
                ],
            ];
        }

        // Strict bool check: the plugin contract is a real boolean, and anything
        // else (HTML error page, proxy JSON, "true" as a string) is not an answer.
        if ($http === 200 && is_array($json) && is_bool($json['success'] ?? null)) {
            $status = is_array($json['status'] ?? null) ? $json['status'] : null;
            $warnings = is_array($json['warnings'] ?? null) ? $json['warnings'] : [];

            if ($json['success']) {
                return [
                    'outcome' => 'ok',
                    'code' => 200,
                    'body' => [
                        'success' => true,
                        'message' => $json['message'] ?? 'Done',
                        'warnings' => $warnings,
                        'status' => $status,
                    ],
                ];
            }

            $busy = ($json['reason'] ?? null) === 'busy';

            return [
                'outcome' => $busy ? 'busy' : 'failed',
                'code' => $busy ? 409 : 422,
                'body' => [
                    'success' => false,
                    'reason' => $json['reason'] ?? null,
                    'message' => $json['message'] ?? 'The site could not apply the change',
                    'warnings' => $warnings,
                    'status' => $status,
                ],
            ];
        }

        // No response, timeout, 5xx, or anything we cannot read as a plugin answer.
        return [
            'outcome' => 'unreachable',
            'code' => 502,
            'body' => [
                'success' => false,
                'reason' => 'unreachable',
                'message' => 'Outcome unknown — refresh status',
            ],
        ];
    }

    /**
     * The `outcome` string for the 'hardening' audit log line: `ok` for a
     * successful call, the plugin's own `reason` for a plugin-side failure
     * (busy / a rule-specific reason), or the platform outcome itself for
     * plugin_outdated / unauthorized / unreachable.
     *
     * @param  array{outcome: string, code: int, body: array}  $mapped
     */
    public static function auditOutcome(array $mapped): string
    {
        if ($mapped['outcome'] === 'busy' || $mapped['outcome'] === 'failed') {
            return $mapped['body']['reason'] ?? $mapped['outcome'];
        }

        return $mapped['outcome'];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class SiteReviewProxyController extends Controller
{
    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const MAX_REDIRECTS = 5;

    // Script injected into every proxied page to relay scroll position to parent
    private const SCROLL_SCRIPT = <<<'JS'
<script id="__lsm_scroll_tracker__">
(function(){
  function send(){window.parent.postMessage({t:'lsm-scroll',y:window.pageYOffset},'*');}
  window.addEventListener('scroll', send, {passive:true});
  send();
})();
</script>
JS;

    /**
     * Decide whether a URL must be blocked for SSRF safety.
     *
     * Blocks non-http(s) schemes, hosts that fail to resolve, and any host
     * that resolves to (or is) a private/reserved/link-local address — which
     * includes cloud metadata endpoints like 169.254.169.254.
     */
    private function isBlockedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return true;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return true;
        }

        // Strip brackets from IPv6 literals, e.g. [::1]
        $host = trim($parts['host'], '[]');

        // IP literal → validate directly; hostname → validate ALL resolved A records.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
        }

        if (empty($ips)) {
            return true; // fail closed when resolution fails
        }

        foreach ($ips as $ip) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($public === false) {
                return true; // any private/reserved hop → block the whole request
            }
        }

        return false;
    }

    /**
     * Resolve a redirect Location (absolute or relative) into an absolute URL.
     * Relative locations stay on the already-validated host.
     */
    private function resolveLocation(string $baseUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_HOST)) {
            return $location; // absolute — will be re-validated by the caller
        }

        $b = parse_url($baseUrl);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '')
            . (isset($b['port']) ? ':' . $b['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $basePath = isset($b['path']) ? rtrim(dirname($b['path']), '/') : '';

        return $origin . $basePath . '/' . $location;
    }

    public function proxy(Request $request): Response
    {
        $url = $request->query('url');

        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return response('Invalid URL', 400);
        }

        // Follow redirects manually so every hop is re-validated for SSRF —
        // a public URL must not be able to bounce us to an internal address.
        $currentUrl = $url;
        $upstream = null;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if ($this->isBlockedUrl($currentUrl)) {
                return response('Forbidden', 403);
            }

            try {
                $upstream = Http::timeout(15)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LSMReview/1.0)'])
                    ->get($currentUrl);
            } catch (\Exception $e) {
                return response('Failed to fetch: ' . $e->getMessage(), 502);
            }

            $status = $upstream->status();
            if ($status < 300 || $status >= 400) {
                break; // not a redirect
            }

            $location = $upstream->header('Location');
            if (! $location) {
                break;
            }

            $currentUrl = $this->resolveLocation($currentUrl, $location);
        }

        if ($upstream === null || ($upstream->status() >= 300 && $upstream->status() < 400)) {
            return response('Too many redirects', 502);
        }

        $contentType = $upstream->header('Content-Type') ?? 'text/html';

        // Only process HTML — pass everything else (CSS, images, fonts) straight through
        if (! str_contains($contentType, 'text/html')) {
            return response($upstream->body(), $upstream->status())
                ->withHeaders(['Content-Type' => $contentType]);
        }

        $html = $upstream->body();

        // Inject <base> so relative assets resolve against the original host
        $parsed  = parse_url($currentUrl);
        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $basePath = isset($parsed['path']) ? dirname($parsed['path']) . '/' : '/';
        $baseHref = rtrim($baseUrl . $basePath, '/') . '/';

        $inject = "<base href=\"{$baseHref}\">\n" . self::SCROLL_SCRIPT;

        // Insert right after <head> (case-insensitive)
        $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . $inject, $html, 1);

        return response($html, 200)
            ->withHeaders([
                'Content-Type'    => 'text/html; charset=utf-8',
                'X-Frame-Options' => '', // cleared — we're serving it ourselves
                'Cache-Control'   => 'no-store',
            ]);
    }
}

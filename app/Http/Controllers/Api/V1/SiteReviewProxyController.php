<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class SiteReviewProxyController extends Controller
{
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

    public function proxy(Request $request): Response
    {
        $url = $request->query('url');

        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return response('Invalid URL', 400);
        }

        try {
            $upstream = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LSMReview/1.0)'])
                ->get($url);
        } catch (\Exception $e) {
            return response('Failed to fetch: ' . $e->getMessage(), 502);
        }

        $contentType = $upstream->header('Content-Type') ?? 'text/html';

        // Only process HTML — pass everything else (CSS, images, fonts) straight through
        if (! str_contains($contentType, 'text/html')) {
            return response($upstream->body(), $upstream->status())
                ->withHeaders(['Content-Type' => $contentType]);
        }

        $html = $upstream->body();

        // Inject <base> so relative assets resolve against the original host
        $parsed  = parse_url($url);
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

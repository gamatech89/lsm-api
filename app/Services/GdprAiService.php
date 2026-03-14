<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GdprAiService
{
    protected ?string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Analyze a screenshot to detect cookie banner, buttons, and language.
     * Returns structured JSON or null on failure.
     */
    public function analyzeBanner(string $screenshotPath): ?array
    {
        if (!$this->apiKey || !file_exists($screenshotPath)) {
            return null;
        }

        try {
            $imageData = base64_encode(file_get_contents($screenshotPath));
            $mediaType = 'image/png';

            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post($this->baseUrl . '/messages', [
                    'model' => $this->model,
                    'max_tokens' => 1024,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'image',
                                    'source' => [
                                        'type' => 'base64',
                                        'media_type' => $mediaType,
                                        'data' => $imageData,
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $this->getBannerAnalysisPrompt(),
                                ],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('GDPR AI banner analysis failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? '';

            // Extract JSON from response
            return $this->parseJsonFromResponse($text);

        } catch (\Exception $e) {
            Log::warning('GDPR AI banner analysis exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate an AI-powered GDPR compliance summary from raw audit data.
     * Returns structured JSON or null on failure.
     */
    public function generateSummary(array $auditData, string $url): ?array
    {
        if (!$this->apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(45)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post($this->baseUrl . '/messages', [
                    'model' => $this->model,
                    'max_tokens' => 2048,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $this->getSummaryPrompt($auditData, $url),
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('GDPR AI summary generation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? '';

            return $this->parseJsonFromResponse($text);

        } catch (\Exception $e) {
            Log::warning('GDPR AI summary exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Prompt for screenshot-based banner analysis.
     */
    protected function getBannerAnalysisPrompt(): string
    {
        return <<<'PROMPT'
Analyze this website screenshot for GDPR cookie consent compliance. Look carefully at the entire page.

Return ONLY valid JSON (no markdown, no code fences) with this exact structure:
{
  "bannerFound": true/false,
  "bannerType": "overlay" | "bar" | "modal" | "widget_only" | "none",
  "solution": "Borlabs Cookie" | "Cookiebot" | "CookieYes" | "Real Cookie Banner" | "Complianz" | "OneTrust" | "Custom" | "Unknown" | null,
  "language": "de" | "en" | "fr" | etc,
  "buttons": [
    {
      "text": "exact button text",
      "role": "accept_all" | "reject" | "settings" | "save" | "content_unblock" | "other",
      "prominent": true/false
    }
  ],
  "observations": "Brief text about what you see (e.g. 'Banner is a full overlay with dark backdrop', 'Only a small widget icon visible in bottom-left corner, no banner displayed')"
}

IMPORTANT distinctions:
- "content_unblock" buttons (e.g. "Erforderlichen Service akzeptieren und Inhalte entsperren") are NOT consent buttons — they unblock specific embedded content (videos, maps)
- "accept_all" is the main GDPR consent button that accepts ALL cookies (e.g. "Alle akzeptieren", "Accept All", "Ich akzeptiere alle")
- "reject" is the button to reject non-essential cookies (e.g. "Nur essenzielle Cookies akzeptieren", "Reject All", "Ablehnen")
- If you see only a small widget icon (often bottom-left corner) with no banner, set bannerType to "widget_only"
- Look for hidden or partially visible banners behind overlays
PROMPT;
    }

    /**
     * Prompt for generating the GDPR compliance summary.
     */
    protected function getSummaryPrompt(array $auditData, string $url): string
    {
        $dataJson = json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are a GDPR/TDDDG compliance auditor for German websites. Analyze the following raw audit data from a Puppeteer-based scan of {$url}.

RAW AUDIT DATA:
{$dataJson}

Based on this data, return ONLY valid JSON (no markdown, no code fences) with this structure:
{
  "score": 0-100,
  "verdict": "Compliant" | "Partially Compliant" | "Non-Compliant" | "Critical Violations",
  "summary": "2-3 sentence overview of the compliance status",
  "violations": [
    {
      "severity": "critical" | "high" | "medium" | "low",
      "title": "Short title",
      "description": "What was found",
      "legalRef": "TDDDG §25 / GDPR Art. 5(1)(a) / etc.",
      "recommendation": "How to fix it"
    }
  ],
  "positives": ["List of things done correctly"],
  "recommendations": [
    {
      "priority": "high" | "medium" | "low",
      "action": "Specific action to take"
    }
  ]
}

DATA INTERPRETATION RULES (follow these exactly):
- "preTracking" in the preConsent scenario = tracking requests that fired BEFORE any user interaction. If this array is non-empty, tracking fires before consent — this is a CRITICAL violation.
- "trackingCookies" in the preConsent scenario = tracking cookies set BEFORE consent. If non-empty, cookies are set without consent — this is a CRITICAL violation.
- "scenarios.reject.postTracking" = tracking that fires AFTER the user clicks "Reject". If non-empty, the reject flow is broken.
- "scenarios.acceptAll.postTracking" = tracking that fires AFTER the user clicks "Accept All". This is EXPECTED and normal.

CRITICAL CONSISTENCY RULES:
- NEVER add a positive that contradicts a violation. For example:
  - If preTracking has items, do NOT say "No tracking before consent"
  - If trackingCookies has items, do NOT say "No cookies before consent"
  - If reject flow has postTracking items, do NOT say "Reject flow works correctly"
- ONLY list a positive if the data EXPLICITLY supports it
- Be data-driven: count actual items, name actual services
- If in doubt, do NOT list it as a positive

SCORING GUIDELINES:
- Start at 100, deduct points:
  - Tracking requests before consent: -30 (critical TDDDG §25 violation)
  - Each additional tracking service pre-consent: -5
  - Tracking cookies before consent: -10 per cookie type
  - No cookie banner: -20
  - No reject option: -15
  - Accept button more prominent than reject: -5
  - Reject flow still allows tracking: -20

IMPORTANT:
- Be specific: name tracking services (GTM, GA4, Facebook SDK, Yandex Metrica)
- Reference German law (TDDDG §25, GDPR Art. 5, 6, 7) where applicable
- Distinguish between cookie banner consent and content blockers (e.g. Borlabs Content Blocker for videos)
- Be concise but actionable in recommendations
PROMPT;
    }

    /**
     * Extract JSON object from AI response text.
     */
    protected function parseJsonFromResponse(string $text): ?array
    {
        // Try direct parse first
        $decoded = json_decode(trim($text), true);
        if ($decoded !== null) {
            return $decoded;
        }

        // Try extracting JSON from markdown code block
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $text, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        // Try extracting first { ... } block
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        Log::warning('GDPR AI: Could not parse JSON from response', ['text' => substr($text, 0, 500)]);
        return null;
    }
}

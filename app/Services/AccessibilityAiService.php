<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AccessibilityAiService
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
     * Analyze a screenshot for visual accessibility issues.
     * Returns structured JSON or null on failure.
     */
    public function analyzeScreenshot(string $screenshotPath): ?array
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
                                    'text' => $this->getScreenshotAnalysisPrompt(),
                                ],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Accessibility AI screenshot analysis failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? '';

            return $this->parseJsonFromResponse($text);

        } catch (\Exception $e) {
            Log::warning('Accessibility AI screenshot analysis exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate an AI-powered accessibility compliance summary from raw audit data.
     * Returns structured JSON or null on failure.
     */
    public function generateSummary(array $auditData, string $url, string $locale = 'en'): ?array
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
                            'content' => $this->getSummaryPrompt($auditData, $url, $locale),
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Accessibility AI summary generation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? '';

            return $this->parseJsonFromResponse($text);

        } catch (\Exception $e) {
            Log::warning('Accessibility AI summary exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Prompt for screenshot-based accessibility analysis.
     */
    protected function getScreenshotAnalysisPrompt(): string
    {
        return <<<'PROMPT'
Analyze this website screenshot for visual accessibility issues. Look carefully at the entire page.

Return ONLY valid JSON (no markdown, no code fences) with this exact structure:
{
  "visualIssues": [
    {
      "type": "contrast" | "text-size" | "layout" | "color-only" | "readability" | "spacing" | "other",
      "severity": "critical" | "serious" | "moderate" | "minor",
      "description": "What you see",
      "location": "Where on the page"
    }
  ],
  "observations": "Brief overall assessment of the page's visual accessibility",
  "positives": ["List of things visually done well for accessibility"],
  "estimatedContrastIssues": true/false,
  "hasReadableTypography": true/false,
  "hasAdequateSpacing": true/false,
  "hasClearVisualHierarchy": true/false
}

Focus on:
- Text contrast against backgrounds (especially light gray text)
- Text size (minimum 16px for body text)
- Touch target sizes for buttons and links
- Visual hierarchy (proper heading sizes)
- Color usage (information conveyed by color alone)
- Spacing and readability
- Any flashing or animation concerns
PROMPT;
    }

    /**
     * Prompt for generating the accessibility compliance summary.
     */
    protected function getSummaryPrompt(array $auditData, string $url, string $locale = 'en'): string
    {
        // Only include essential data to keep token usage reasonable
        $essentialData = [
            'score' => $auditData['score'] ?? 0,
            'wcagLevel' => $auditData['wcagLevel'] ?? 'AA',
            'summary' => $auditData['summary'] ?? [],
            'violations' => array_map(function ($v) {
                return [
                    'id' => $v['id'],
                    'impact' => $v['impact'],
                    'category' => $v['category'],
                    'description' => $v['description'],
                    'help' => $v['help'],
                    'wcag' => $v['wcag'] ?? [],
                    'nodeCount' => $v['nodeCount'] ?? 0,
                ];
            }, array_slice($auditData['violations'] ?? [], 0, 20)),
            'customChecks' => array_filter($auditData['customChecks'] ?? [], fn($c) => ($c['status'] ?? '') !== 'pass'),
        ];

        $dataJson = json_encode($essentialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $languageInstruction = $locale === 'de'
            ? "\nRESPONSE LANGUAGE: Write ALL text fields (summary, violation titles/descriptions/recommendations, positives, recommendation actions) in German. Keep only technical terms, WCAG criteria, and element names in their original language."
            : '';

        return <<<PROMPT
You are a web accessibility (WCAG 2.1) compliance auditor. Analyze the following automated audit data from an axe-core scan of {$url}.

RAW AUDIT DATA:
{$dataJson}

Based on this data, return ONLY valid JSON (no markdown, no code fences) with this structure:
{
  "score": 0-100,
  "verdict": "Accessible" | "Partially Accessible" | "Needs Improvement" | "Critical Issues",
  "summary": "2-3 sentence overview of the accessibility status",
  "violations": [
    {
      "severity": "critical" | "serious" | "moderate" | "minor",
      "title": "Short, user-friendly title",
      "description": "What was found in plain language",
      "wcagRef": "WCAG 2.1 criterion (e.g. 1.1.1 Non-text Content)",
      "recommendation": "How to fix it in clear, actionable terms"
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

IMPORTANT RULES:
- Translate technical axe-core violation IDs into user-friendly descriptions
- Be specific: name the violation type (missing alt text, insufficient contrast, etc.)
- Reference WCAG criteria where applicable
- Make recommendations actionable for non-technical users
- If the score is already provided, you may adjust it based on your analysis, but don't deviate more than ±10 points from the mechanical score
- Prioritize violations by impact: critical > serious > moderate > minor
- Group similar violations together in your summary
{$languageInstruction}
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

        Log::warning('Accessibility AI: Could not parse JSON from response', ['text' => substr($text, 0, 500)]);
        return null;
    }
}

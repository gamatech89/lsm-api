<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ClaudeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatController extends Controller
{
    public function __construct(private ClaudeService $claudeService)
    {
    }

    /**
     * Handle AI chat messages.
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|string|uuid',
            'history' => 'nullable|array',
            'history.*.role' => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
            'language' => 'nullable|string|in:en,de',
        ]);

        $user = $request->user();

        // Check if user is admin (for now, restrict to admins only)
        if ($user->role !== 'admin') {
            return $this->errorResponse('AI Chat is currently available for admins only.', 403);
        }

        // Check if API key is configured
        if (empty(config('services.anthropic.api_key'))) {
            return $this->errorResponse('AI Chat is not configured. Please set ANTHROPIC_API_KEY.', 503);
        }

        $message = $request->input('message');
        $conversationId = $request->input('conversation_id', \Illuminate\Support\Str::uuid()->toString());
        $language = $request->input('language', 'en');
        
        // Build context with conversation history and language
        $context = [
            'language' => $language,
        ];
        $history = $request->input('history', []);
        
        if (!empty($history)) {
            // Convert history to Claude message format
            $context['history'] = array_map(function ($msg) {
                return [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
            }, $history);
        }

        $result = $this->claudeService->chat($message, $context);

        if (!$result['success']) {
            return $this->errorResponse($result['error'] ?? 'An error occurred', 500);
        }

        return $this->successResponse([
            'response' => $result['response'],
            'conversation_id' => $conversationId,
            'tools_used' => $result['tools_used'] ?? [],
        ]);
    }
}

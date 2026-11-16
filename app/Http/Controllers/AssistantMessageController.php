<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssistantMessageRequest;
use App\Services\AdComAssistant;
use Illuminate\Http\JsonResponse;

class AssistantMessageController extends Controller
{
    public function store(StoreAssistantMessageRequest $request, AdComAssistant $assistant): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $visitor = $user !== null
            ? 'user:'.$user->getAuthIdentifier().':'.$user->role
            : 'guest:'.$request->session()->getId();

        $sessionKey = 'adi_context_history.'.hash('sha256', $visitor);
        $explicitMessages = $validated['messages'] ?? [];

        /** @var array<int, array{role: string, content: string}> $contextMessages */
        $contextMessages = $request->has('messages')
            ? $explicitMessages
            : ($request->hasSession() ? (array) $request->session()->get($sessionKey, []) : []);

        $result = $assistant->respond(
            message: $validated['message'],
            messages: $explicitMessages,
            safetyIdentifier: hash_hmac('sha256', $visitor, (string) config('app.key')),
            user: $user,
            pageContext: is_array($validated['page_context'] ?? null) ? $validated['page_context'] : [],
            contextMessages: $contextMessages,
        );

        if ($request->hasSession()) {
            $updatedHistory = array_slice([
                ...$contextMessages,
                ['role' => 'user', 'content' => $validated['message']],
                ['role' => 'assistant', 'content' => $result['reply']],
            ], -8);
            $request->session()->put($sessionKey, $updatedHistory);
        }

        return response()->json($result);
    }
}

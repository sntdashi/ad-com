<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmAssistantActionRequest;
use App\Services\Assistant\AssistantActionCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantActionController extends Controller
{
    public function confirm(
        ConfirmAssistantActionRequest $request,
        string $proposal,
        AssistantActionCoordinator $coordinator,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Autentikasi diperlukan.'], 401);
        }

        $result = $coordinator->confirm(
            actionId: $proposal,
            actor: $user,
            submittedPayloadHash: (string) $request->validated('payload_hash'),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'action_proposal' => $result['action_proposal'],
            'actions' => $result['actions'],
        ], $result['http_status']);
    }

    public function cancel(
        Request $request,
        string $proposal,
        AssistantActionCoordinator $coordinator,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Autentikasi diperlukan.'], 401);
        }

        $result = $coordinator->cancel(
            actionId: $proposal,
            actor: $user,
            ipAddress: $request->ip(),
        );

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'action_proposal' => $result['action_proposal'],
            'actions' => [],
        ], $result['http_status']);
    }
}

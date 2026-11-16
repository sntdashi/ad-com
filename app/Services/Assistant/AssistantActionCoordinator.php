<?php

namespace App\Services\Assistant;

use App\Models\AssistantActionProposal;
use App\Models\User;
use App\Services\Assistant\Actions\AssistantActionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AssistantActionCoordinator
{
    public const TTL_MINUTES = 15;

    /** @var array<string, AssistantActionHandler> */
    private array $handlers = [];

    /**
     * @param  iterable<int, AssistantActionHandler>  $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(AssistantActionHandler $handler): void
    {
        $this->handlers[$handler->name()] = $handler;
    }

    public function has(string $actionName): bool
    {
        return isset($this->handlers[$actionName]);
    }

    /**
     * Propose a controlled operational action through REQUESTED -> VALIDATED -> PREVIEWED -> AWAITING_CONFIRMATION.
     *
     * @param  array<string, mixed>  $rawPayload
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $pageContext
     * @return array{
     *     status: string,
     *     authorized: bool,
     *     valid: bool,
     *     message: string,
     *     action_proposal: array<string, mixed>|null,
     *     actions: list<array{label: string, href: string}>,
     *     evidence: array<string, mixed>|null
     * }
     */
    public function propose(
        string $actionName,
        ?User $actor,
        array $rawPayload,
        array $pageContext = [],
        ?string $ipAddress = null,
    ): array {
        $handler = $this->handlers[$actionName] ?? null;

        if ($handler === null) {
            return [
                'status' => AssistantActionProposal::STATUS_FAILED,
                'authorized' => false,
                'valid' => false,
                'message' => 'Aksi operasional ini belum tersedia pada konfigurasi ADI saat ini.',
                'action_proposal' => null,
                'actions' => [],
                'evidence' => null,
            ];
        }

        if ($actor === null || ! $actor->is_active) {
            return [
                'status' => AssistantActionProposal::STATUS_DENIED,
                'authorized' => false,
                'valid' => false,
                'message' => 'Akses ditolak. Masuk ke akun AD Com yang aktif terlebih dahulu agar ADI dapat menyiapkan pratinjau aksi.',
                'action_proposal' => null,
                'actions' => [
                    ['label' => 'Masuk ke Akun', 'href' => route('login')],
                ],
                'evidence' => null,
            ];
        }

        $proposalId = (string) Str::ulid();
        $idempotencyKey = (string) Str::ulid();
        $expiresAt = now()->addMinutes(self::TTL_MINUTES)->toImmutable();

        $proposal = AssistantActionProposal::query()->create([
            'id' => $proposalId,
            'user_id' => $actor->id,
            'action_name' => $actionName,
            'status' => AssistantActionProposal::STATUS_REQUESTED,
            'payload' => $this->sanitizePayload($rawPayload),
            'payload_hash' => $this->computePayloadHash($proposalId, $actor->id, $actionName, $rawPayload),
            'preview_summary' => null,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => $expiresAt,
        ]);

        $this->recordAudit(
            actorId: $actor->id,
            event: 'assistant.action_requested',
            proposalId: $proposal->id,
            before: null,
            after: [
                'action_name' => $actionName,
                'status' => AssistantActionProposal::STATUS_REQUESTED,
            ],
            ipAddress: $ipAddress,
        );

        $preAuthError = $handler->authorize($actor, $rawPayload);
        if ($preAuthError !== null) {
            return $this->denyProposal($proposal, $actor, $preAuthError, $ipAddress);
        }

        $validation = $handler->validateAndNormalize($actor, $rawPayload, $pageContext);
        if (! $validation['valid']) {
            $reason = $validation['message'] ?? 'Parameter aksi belum lengkap atau tidak valid.';
            $proposal->update([
                'status' => AssistantActionProposal::STATUS_FAILED,
                'failure_reason' => Str::limit($reason, 500, ''),
            ]);

            $this->recordAudit(
                actorId: $actor->id,
                event: 'assistant.action_validation_failed',
                proposalId: $proposal->id,
                before: ['status' => AssistantActionProposal::STATUS_REQUESTED],
                after: [
                    'action_name' => $actionName,
                    'status' => AssistantActionProposal::STATUS_FAILED,
                    'missing_fields' => $validation['missing_fields'],
                    'errors' => $validation['errors'],
                ],
                ipAddress: $ipAddress,
            );

            return [
                'status' => AssistantActionProposal::STATUS_FAILED,
                'authorized' => true,
                'valid' => false,
                'message' => $reason,
                'action_proposal' => null,
                'actions' => [],
                'evidence' => null,
            ];
        }

        $normalizedPayload = $validation['payload'];
        $postAuthError = $handler->authorize($actor, $normalizedPayload);
        if ($postAuthError !== null) {
            return $this->denyProposal($proposal, $actor, $postAuthError, $ipAddress);
        }

        $canonicalHash = $this->computePayloadHash($proposal->id, $actor->id, $actionName, $normalizedPayload);

        $proposal->update([
            'status' => AssistantActionProposal::STATUS_VALIDATED,
            'payload' => $normalizedPayload,
            'payload_hash' => $canonicalHash,
        ]);

        $this->recordAudit(
            actorId: $actor->id,
            event: 'assistant.action_validated',
            proposalId: $proposal->id,
            before: ['status' => AssistantActionProposal::STATUS_REQUESTED],
            after: [
                'action_name' => $actionName,
                'status' => AssistantActionProposal::STATUS_VALIDATED,
            ],
            ipAddress: $ipAddress,
        );

        $preview = $handler->buildPreview($actor, $normalizedPayload);

        $proposal->update([
            'status' => AssistantActionProposal::STATUS_PREVIEWED,
            'preview_summary' => $preview,
        ]);

        $this->recordAudit(
            actorId: $actor->id,
            event: 'assistant.action_previewed',
            proposalId: $proposal->id,
            before: ['status' => AssistantActionProposal::STATUS_VALIDATED],
            after: [
                'action_name' => $actionName,
                'status' => AssistantActionProposal::STATUS_PREVIEWED,
                'target' => $preview['target'],
            ],
            ipAddress: $ipAddress,
        );

        $proposal->update([
            'status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION,
        ]);

        $this->recordAudit(
            actorId: $actor->id,
            event: 'assistant.action_awaiting_confirmation',
            proposalId: $proposal->id,
            before: ['status' => AssistantActionProposal::STATUS_PREVIEWED],
            after: [
                'action_name' => $actionName,
                'status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION,
                'expires_at' => $proposal->expires_at->toIso8601String(),
            ],
            ipAddress: $ipAddress,
        );

        $serializedProposal = $this->serializeProposal($proposal->fresh() ?? $proposal, $handler->label());

        return [
            'status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION,
            'authorized' => true,
            'valid' => true,
            'message' => $preview['summary_text'],
            'action_proposal' => $serializedProposal,
            'actions' => [],
            'evidence' => [
                'verified' => true,
                'source' => 'AD Com Action Guard',
                'period_label' => 'Pratinjau Aksi Tervalidasi',
                'generated_at' => now()->toIso8601String(),
                'data_insufficient' => false,
                'facts' => [
                    ['label' => 'Aksi', 'value' => $handler->label()],
                    ['label' => 'Target', 'value' => $preview['target']],
                    ['label' => 'Status', 'value' => 'Menunggu Konfirmasi Pengguna'],
                ],
                'insights' => [],
                'recommendations' => $preview['warnings'],
            ],
        ];
    }

    /**
     * Confirm and execute a pending proposal with atomic lock, ownership, hash, expiration, and policy revalidation.
     *
     * @return array{
     *     http_status: int,
     *     status: string,
     *     message: string,
     *     action_proposal: array<string, mixed>|null,
     *     actions: list<array{label: string, href: string}>
     * }
     */
    public function confirm(
        string $actionId,
        User $actor,
        string $submittedPayloadHash,
        ?string $ipAddress = null,
    ): array {
        $lock = Cache::lock("assistant_action:{$actionId}", 10);
        if (! $lock->get()) {
            return [
                'http_status' => 409,
                'status' => 'conflict',
                'message' => 'Aksi ini sedang diproses. Mohon tunggu sebentar.',
                'action_proposal' => null,
                'actions' => [],
            ];
        }

        try {
            $proposal = AssistantActionProposal::query()->find($actionId);
            if ($proposal === null) {
                return [
                    'http_status' => 404,
                    'status' => 'not_found',
                    'message' => 'Proposal aksi tidak ditemukan.',
                    'action_proposal' => null,
                    'actions' => [],
                ];
            }

            if ((int) $proposal->user_id !== (int) $actor->id) {
                $this->recordAudit(
                    actorId: $actor->id,
                    event: 'assistant.action_cross_user_rejected',
                    proposalId: $proposal->id,
                    before: ['owner_user_id' => $proposal->user_id],
                    after: ['attempted_actor_id' => $actor->id],
                    ipAddress: $ipAddress,
                );

                return [
                    'http_status' => 403,
                    'status' => 'forbidden',
                    'message' => 'Akses ditolak. Anda hanya dapat mengonfirmasi proposal aksi milik akun Anda sendiri.',
                    'action_proposal' => null,
                    'actions' => [],
                ];
            }

            if (! hash_equals($proposal->payload_hash, $submittedPayloadHash)) {
                $this->recordAudit(
                    actorId: $actor->id,
                    event: 'assistant.action_tamper_rejected',
                    proposalId: $proposal->id,
                    before: ['status' => $proposal->status],
                    after: ['reason' => 'payload_hash_mismatch'],
                    ipAddress: $ipAddress,
                );

                return [
                    'http_status' => 422,
                    'status' => 'tampered',
                    'message' => 'Integritas pratinjau aksi tidak cocok atau telah berubah. Minta ADI membuat pratinjau ulang.',
                    'action_proposal' => $this->serializeProposal($proposal, $this->labelFor($proposal->action_name)),
                    'actions' => [],
                ];
            }

            if ($proposal->status === AssistantActionProposal::STATUS_EXECUTED) {
                $this->recordAudit(
                    actorId: $actor->id,
                    event: 'assistant.action_replay_rejected',
                    proposalId: $proposal->id,
                    before: ['status' => AssistantActionProposal::STATUS_EXECUTED],
                    after: ['reason' => 'duplicate_confirmation'],
                    ipAddress: $ipAddress,
                );

                return [
                    'http_status' => 409,
                    'status' => AssistantActionProposal::STATUS_EXECUTED,
                    'message' => 'Aksi ini sudah pernah dijalankan sebelumnya.',
                    'action_proposal' => $this->serializeProposal($proposal, $this->labelFor($proposal->action_name)),
                    'actions' => [],
                ];
            }

            if ($proposal->status === AssistantActionProposal::STATUS_CANCELLED) {
                return [
                    'http_status' => 409,
                    'status' => AssistantActionProposal::STATUS_CANCELLED,
                    'message' => 'Aksi ini sudah dibatalkan sebelumnya.',
                    'action_proposal' => $this->serializeProposal($proposal, $this->labelFor($proposal->action_name)),
                    'actions' => [],
                ];
            }

            if ($proposal->status === AssistantActionProposal::STATUS_DENIED) {
                return [
                    'http_status' => 403,
                    'status' => AssistantActionProposal::STATUS_DENIED,
                    'message' => $proposal->failure_reason ?: 'Akses aksi ini ditolak.',
                    'action_proposal' => $this->serializeProposal($proposal, $this->labelFor($proposal->action_name)),
                    'actions' => [],
                ];
            }

            if ($proposal->isExpired()) {
                if ($proposal->status !== AssistantActionProposal::STATUS_EXPIRED) {
                    $proposal->update(['status' => AssistantActionProposal::STATUS_EXPIRED]);
                    $this->recordAudit(
                        actorId: $actor->id,
                        event: 'assistant.action_expired',
                        proposalId: $proposal->id,
                        before: ['status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION],
                        after: ['status' => AssistantActionProposal::STATUS_EXPIRED],
                        ipAddress: $ipAddress,
                    );
                }

                return [
                    'http_status' => 410,
                    'status' => AssistantActionProposal::STATUS_EXPIRED,
                    'message' => 'Pratinjau aksi telah kedaluwarsa. Minta ADI membuat pratinjau baru untuk melanjutkan.',
                    'action_proposal' => $this->serializeProposal($proposal->fresh() ?? $proposal, $this->labelFor($proposal->action_name)),
                    'actions' => [],
                ];
            }

            if ($proposal->status !== AssistantActionProposal::STATUS_AWAITING_CONFIRMATION) {
                return [
                    'http_status' => 409,
                    'status' => $proposal->status,
                    'message' => 'Status proposal aksi tidak valid untuk dieksekusi.',
                    'action_proposal' => $this->serializeProposal($proposal, $this->labelFor($proposal->action_name)),
                    'actions' => [],
                ];
            }

            $handler = $this->handlers[$proposal->action_name] ?? null;
            if ($handler === null) {
                return [
                    'http_status' => 422,
                    'status' => AssistantActionProposal::STATUS_FAILED,
                    'message' => 'Handler aksi tidak tersedia.',
                    'action_proposal' => $this->serializeProposal($proposal, $this->labelFor($proposal->action_name)),
                    'actions' => [],
                ];
            }

            /** @var array<string, mixed> $storedPayload */
            $storedPayload = $proposal->payload;

            // Re-evaluate authorization right before execution
            $authError = $handler->authorize($actor, $storedPayload);
            if ($authError !== null) {
                $proposal->update([
                    'status' => AssistantActionProposal::STATUS_DENIED,
                    'failure_reason' => Str::limit($authError, 500, ''),
                ]);

                $this->recordAudit(
                    actorId: $actor->id,
                    event: 'assistant.action_denied',
                    proposalId: $proposal->id,
                    before: ['status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION],
                    after: ['status' => AssistantActionProposal::STATUS_DENIED, 'reason' => $authError],
                    ipAddress: $ipAddress,
                );

                return [
                    'http_status' => 403,
                    'status' => AssistantActionProposal::STATUS_DENIED,
                    'message' => $authError,
                    'action_proposal' => $this->serializeProposal($proposal->fresh() ?? $proposal, $handler->label()),
                    'actions' => [],
                ];
            }

            // Re-evaluate domain validation right before execution
            $revalidation = $handler->validateAndNormalize($actor, $storedPayload);
            if (! $revalidation['valid']) {
                $reason = $revalidation['message'] ?? 'Validasi data terbaru gagal sebelum eksekusi.';
                $proposal->update([
                    'status' => AssistantActionProposal::STATUS_FAILED,
                    'failure_reason' => Str::limit($reason, 500, ''),
                ]);

                $this->recordAudit(
                    actorId: $actor->id,
                    event: 'assistant.action_failed',
                    proposalId: $proposal->id,
                    before: ['status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION],
                    after: ['status' => AssistantActionProposal::STATUS_FAILED, 'reason' => $reason],
                    ipAddress: $ipAddress,
                );

                return [
                    'http_status' => 422,
                    'status' => AssistantActionProposal::STATUS_FAILED,
                    'message' => $reason,
                    'action_proposal' => $this->serializeProposal($proposal->fresh() ?? $proposal, $handler->label()),
                    'actions' => [],
                ];
            }

            $normalizedPayload = $revalidation['payload'];

            try {
                $outcome = DB::transaction(function () use ($proposal, $handler, $actor, $normalizedPayload, $ipAddress): array {
                    $lockedProposal = AssistantActionProposal::query()
                        ->whereKey($proposal->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedProposal->status !== AssistantActionProposal::STATUS_AWAITING_CONFIRMATION) {
                        return ['conflict' => true, 'proposal' => $lockedProposal];
                    }

                    $result = $handler->execute($actor, $normalizedPayload, $lockedProposal, $ipAddress);

                    $lockedProposal->update([
                        'status' => AssistantActionProposal::STATUS_EXECUTED,
                        'confirmed_at' => now(),
                        'executed_at' => now(),
                        'result_reference' => Str::limit($result['reference'], 120, ''),
                        'result_summary' => $this->sanitizePayload($result['summary']),
                    ]);

                    $this->recordAudit(
                        actorId: $actor->id,
                        event: 'assistant.action_executed',
                        proposalId: $lockedProposal->id,
                        before: ['status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION],
                        after: [
                            'action_name' => $lockedProposal->action_name,
                            'status' => AssistantActionProposal::STATUS_EXECUTED,
                            'result_reference' => $result['reference'],
                        ],
                        ipAddress: $ipAddress,
                    );

                    return [
                        'conflict' => false,
                        'proposal' => $lockedProposal->fresh() ?? $lockedProposal,
                        'result' => $result,
                    ];
                });
            } catch (Throwable $exception) {
                $safeFailure = Str::limit($exception->getMessage(), 300, '');
                $proposal->update([
                    'status' => AssistantActionProposal::STATUS_FAILED,
                    'failure_reason' => $safeFailure,
                ]);

                $this->recordAudit(
                    actorId: $actor->id,
                    event: 'assistant.action_failed',
                    proposalId: $proposal->id,
                    before: ['status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION],
                    after: [
                        'action_name' => $proposal->action_name,
                        'status' => AssistantActionProposal::STATUS_FAILED,
                        'reason' => $safeFailure,
                    ],
                    ipAddress: $ipAddress,
                );

                return [
                    'http_status' => 422,
                    'status' => AssistantActionProposal::STATUS_FAILED,
                    'message' => 'Aksi gagal dieksekusi: '.$safeFailure,
                    'action_proposal' => $this->serializeProposal($proposal->fresh() ?? $proposal, $handler->label()),
                    'actions' => [],
                ];
            }

            if ($outcome['conflict'] === true) {
                return [
                    'http_status' => 409,
                    'status' => $outcome['proposal']->status,
                    'message' => 'Aksi ini sudah tidak berada pada status menunggu konfirmasi.',
                    'action_proposal' => $this->serializeProposal($outcome['proposal'], $handler->label()),
                    'actions' => [],
                ];
            }

            /** @var array{reference: string, summary: array<string, mixed>, reply: string, actions: list<array{label: string, href: string}>} $result */
            $result = $outcome['result'];

            return [
                'http_status' => 200,
                'status' => AssistantActionProposal::STATUS_EXECUTED,
                'message' => $result['reply'],
                'action_proposal' => $this->serializeProposal($outcome['proposal'], $handler->label()),
                'actions' => $result['actions'],
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Cancel an awaiting confirmation proposal.
     *
     * @return array{
     *     http_status: int,
     *     status: string,
     *     message: string,
     *     action_proposal: array<string, mixed>|null
     * }
     */
    public function cancel(string $actionId, User $actor, ?string $ipAddress = null): array
    {
        $lock = Cache::lock("assistant_action:{$actionId}", 10);
        if (! $lock->get()) {
            return [
                'http_status' => 409,
                'status' => 'conflict',
                'message' => 'Proposal sedang diproses. Coba sesaat lagi.',
                'action_proposal' => null,
            ];
        }

        try {
            $proposal = AssistantActionProposal::query()->find($actionId);
            if ($proposal === null) {
                return [
                    'http_status' => 404,
                    'status' => 'not_found',
                    'message' => 'Proposal aksi tidak ditemukan.',
                    'action_proposal' => null,
                ];
            }

            if ((int) $proposal->user_id !== (int) $actor->id) {
                $this->recordAudit(
                    actorId: $actor->id,
                    event: 'assistant.action_cross_user_rejected',
                    proposalId: $proposal->id,
                    before: ['owner_user_id' => $proposal->user_id],
                    after: ['attempted_actor_id' => $actor->id, 'operation' => 'cancel'],
                    ipAddress: $ipAddress,
                );

                return [
                    'http_status' => 403,
                    'status' => 'forbidden',
                    'message' => 'Akses ditolak. Anda hanya dapat membatalkan proposal aksi milik Anda sendiri.',
                    'action_proposal' => null,
                ];
            }

            if ($proposal->status !== AssistantActionProposal::STATUS_AWAITING_CONFIRMATION) {
                return [
                    'http_status' => 409,
                    'status' => $proposal->status,
                    'message' => 'Proposal ini sudah tidak dapat dibatalkan karena statusnya sudah berubah.',
                    'action_proposal' => $this->serializeProposal($proposal, $this->labelFor($proposal->action_name)),
                ];
            }

            $proposal->update([
                'status' => AssistantActionProposal::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $this->recordAudit(
                actorId: $actor->id,
                event: 'assistant.action_cancelled',
                proposalId: $proposal->id,
                before: ['status' => AssistantActionProposal::STATUS_AWAITING_CONFIRMATION],
                after: [
                    'action_name' => $proposal->action_name,
                    'status' => AssistantActionProposal::STATUS_CANCELLED,
                ],
                ipAddress: $ipAddress,
            );

            return [
                'http_status' => 200,
                'status' => AssistantActionProposal::STATUS_CANCELLED,
                'message' => 'Aksi dibatalkan oleh pengguna. Tidak ada perubahan data yang dilakukan.',
                'action_proposal' => $this->serializeProposal($proposal->fresh() ?? $proposal, $this->labelFor($proposal->action_name)),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Compute deterministic HMAC-SHA256 hash binding proposal ID, owner user ID, action name, and canonical payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function computePayloadHash(string $actionId, int $userId, string $actionName, array $payload): string
    {
        $canonical = $this->ksortRecursive($payload);
        $encoded = json_encode([
            'action_id' => $actionId,
            'user_id' => $userId,
            'action_name' => $actionName,
            'payload' => $canonical,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeProposal(AssistantActionProposal $proposal, string $actionLabel): array
    {
        $isApiRequest = request()->is('api/*');
        $confirmRoute = $isApiRequest ? 'api.v1.assistant.actions.confirm' : 'assistant.actions.confirm';
        $cancelRoute = $isApiRequest ? 'api.v1.assistant.actions.cancel' : 'assistant.actions.cancel';

        return [
            'action_id' => $proposal->id,
            'action_name' => $proposal->action_name,
            'action_label' => $actionLabel,
            'status' => $proposal->status,
            'payload_hash' => $proposal->payload_hash,
            'expires_at' => $proposal->expires_at->toIso8601String(),
            'ttl_seconds' => max(0, (int) now()->diffInSeconds($proposal->expires_at, false)),
            'preview' => $proposal->preview_summary,
            'result_reference' => $proposal->result_reference,
            'result_summary' => $proposal->result_summary,
            'failure_reason' => $proposal->failure_reason,
            'confirm_url' => route($confirmRoute, ['proposal' => $proposal->id], false),
            'cancel_url' => route($cancelRoute, ['proposal' => $proposal->id], false),
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     authorized: bool,
     *     valid: bool,
     *     message: string,
     *     action_proposal: array<string, mixed>|null,
     *     actions: list<array{label: string, href: string}>,
     *     evidence: array<string, mixed>|null
     * }
     */
    private function denyProposal(
        AssistantActionProposal $proposal,
        User $actor,
        string $reason,
        ?string $ipAddress,
    ): array {
        $proposal->update([
            'status' => AssistantActionProposal::STATUS_DENIED,
            'failure_reason' => Str::limit($reason, 500, ''),
        ]);

        $this->recordAudit(
            actorId: $actor->id,
            event: 'assistant.action_denied',
            proposalId: $proposal->id,
            before: ['status' => AssistantActionProposal::STATUS_REQUESTED],
            after: [
                'action_name' => $proposal->action_name,
                'status' => AssistantActionProposal::STATUS_DENIED,
                'reason' => $reason,
            ],
            ipAddress: $ipAddress,
        );

        return [
            'status' => AssistantActionProposal::STATUS_DENIED,
            'authorized' => false,
            'valid' => false,
            'message' => $reason,
            'action_proposal' => null,
            'actions' => [],
            'evidence' => [
                'verified' => true,
                'source' => 'AD Com Access Policy',
                'period_label' => 'Batasan Hak Akses',
                'generated_at' => now()->toIso8601String(),
                'data_insufficient' => false,
                'facts' => [],
                'insights' => [],
                'recommendations' => [],
            ],
        ];
    }

    private function labelFor(string $actionName): string
    {
        $handler = $this->handlers[$actionName] ?? null;

        return $handler !== null ? $handler->label() : Str::headline($actionName);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordAudit(
        ?int $actorId,
        string $event,
        string $proposalId,
        ?array $before,
        ?array $after,
        ?string $ipAddress = null,
    ): void {
        DB::table('audit_logs')->insert([
            'actor_id' => $actorId,
            'event' => $event,
            'auditable_type' => AssistantActionProposal::class,
            'auditable_id' => $proposalId,
            'before' => $before !== null ? json_encode($this->sanitizePayload($before)) : null,
            'after' => $after !== null ? json_encode($this->sanitizePayload($after)) : null,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }

    /**
     * Recursively strip sensitive credentials/secrets from audit and stored payloads.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function sanitizePayload(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $keyString = strtolower((string) $key);
            if (str_contains($keyString, 'password')
                || str_contains($keyString, 'secret')
                || str_contains($keyString, 'token')
                || str_contains($keyString, 'access_code')
                || str_contains($keyString, 'otp')) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitizePayload($value) : $value;
        }

        return $sanitized;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function ksortRecursive(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->ksortRecursive($value);
            }
        }

        return $data;
    }
}

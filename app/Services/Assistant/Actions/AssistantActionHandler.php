<?php

namespace App\Services\Assistant\Actions;

use App\Models\AssistantActionProposal;
use App\Models\User;

interface AssistantActionHandler
{
    public function name(): string;

    public function label(): string;

    /**
     * Authorize actor for the requested write operation.
     * Returns null when authorized, or a user-facing denial explanation when unauthorized.
     *
     * @param  array<string, mixed>  $payload
     */
    public function authorize(User $actor, array $payload): ?string;

    /**
     * Validate and normalize raw action payload using existing application rules.
     *
     * @param  array<string, mixed>  $rawPayload
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $context
     * @return array{
     *     valid: bool,
     *     payload: array<string, mixed>,
     *     errors: array<string, string>,
     *     missing_fields: list<string>,
     *     message: string|null
     * }
     */
    public function validateAndNormalize(User $actor, array $rawPayload, array $context = []): array;

    /**
     * Build a structured confirmation preview from the normalized payload.
     *
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{
     *     title: string,
     *     target: string,
     *     proposed_changes: list<array{label: string, before?: string|null, after: string}>,
     *     financial_impact: array{label: string, amount: int|null, formatted: string|null, breakdown?: list<array{label: string, value: string}>}|null,
     *     warnings: list<string>,
     *     summary_text: string
     * }
     */
    public function buildPreview(User $actor, array $normalizedPayload): array;

    /**
     * Execute the domain mutation inside a transaction after confirmation and revalidation.
     *
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{
     *     reference: string,
     *     summary: array<string, mixed>,
     *     reply: string,
     *     actions: list<array{label: string, href: string}>
     * }
     */
    public function execute(
        User $actor,
        array $normalizedPayload,
        AssistantActionProposal $proposal,
        ?string $ipAddress = null,
    ): array;
}

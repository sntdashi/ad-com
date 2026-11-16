<?php

namespace App\Http\Controllers;

use App\Models\OrderWorkEvidence;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderWorkEvidenceController extends Controller
{
    public function store(Request $request, ServiceOrder $order): RedirectResponse
    {
        Gate::authorize('update', $order);

        $validated = $request->validate([
            'stage' => ['required', Rule::in(['before', 'after', 'completion'])],
            'photo' => [
                Rule::requiredIf(fn (): bool => in_array($request->input('stage'), ['before', 'after'], true)),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'caption' => [
                Rule::requiredIf(fn (): bool => $request->input('stage') === 'completion'),
                'nullable',
                'string',
                'max:2000',
            ],
        ], [
            'photo.required' => 'Unggah foto dokumentasi pengerjaan.',
            'photo.mimes' => 'Format foto dokumentasi harus JPG, PNG, atau WEBP.',
            'photo.max' => 'Ukuran foto maksimal 5 MB.',
            'caption.required' => 'Catatan penyelesaian wajib diisi untuk bukti penyelesaian.',
        ]);

        $actor = $request->user();
        $photo = $request->file('photo');
        $storedPath = null;
        $originalName = null;
        $mimeType = null;
        $fileSize = null;

        if ($photo !== null) {
            $storedPath = $photo->store("work-evidences/{$order->id}", 'local');
            if ($storedPath === false) {
                throw new RuntimeException('Gagal menyimpan file bukti pengerjaan.');
            }
            $originalName = $photo->getClientOriginalName();
            $mimeType = $photo->getClientMimeType();
            $fileSize = (int) $photo->getSize();
        }

        $evidence = DB::transaction(function () use (
            $request,
            $order,
            $actor,
            $validated,
            $storedPath,
            $originalName,
            $mimeType,
            $fileSize,
        ): OrderWorkEvidence {
            $created = OrderWorkEvidence::query()->create([
                'service_order_id' => $order->id,
                'uploaded_by' => $actor->id,
                'stage' => (string) $validated['stage'],
                'file_path' => $storedPath,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'caption' => isset($validated['caption']) && trim((string) $validated['caption']) !== ''
                    ? trim((string) $validated['caption'])
                    : null,
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'work_evidence.submitted',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $order->id,
                'before' => null,
                'after' => json_encode([
                    'evidence_id' => $created->id,
                    'stage' => $created->stage,
                    'has_photo' => $storedPath !== null,
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return $created;
        });

        $order->loadMissing(['customer', 'supervisor']);
        $recipients = collect([$order->customer, $order->supervisor])
            ->merge(User::query()->whereIn('role', ['operator', 'admin', 'supervisor'])->where('is_active', true)->get())
            ->filter(fn ($u): bool => $u instanceof User && $u->is_active && $u->id !== $actor->id)
            ->unique('id')
            ->values();

        if ($recipients->isNotEmpty()) {
            $stageLabel = match ($evidence->stage) {
                'before' => 'sebelum pengerjaan',
                'after' => 'setelah pengerjaan',
                default => 'penyelesaian pekerjaan',
            };

            app(NotificationDispatcher::class)->notifyUsers(
                $recipients,
                'work.evidence_submitted',
                "Dokumentasi baru: {$order->ticket_number}",
                "{$actor->name} mengunggah bukti {$stageLabel} pada tiket {$order->ticket_number}.",
                route('orders.show', $order),
                ['ticket_number' => $order->ticket_number, 'evidence_id' => $evidence->id, 'stage' => $evidence->stage],
            );
        }

        return back()->with('success', 'Dokumentasi pengerjaan berhasil disimpan.');
    }

    public function file(Request $request, ServiceOrder $order, OrderWorkEvidence $workEvidence): StreamedResponse
    {
        Gate::authorize('view', $order);
        abort_unless($workEvidence->service_order_id === $order->id, 404);
        abort_unless($workEvidence->file_path !== null && Storage::disk('local')->exists($workEvidence->file_path), 404);

        return Storage::disk('local')->response(
            $workEvidence->file_path,
            $workEvidence->original_name ?? 'evidence',
            [
                'Content-Type' => $workEvidence->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    public function confirm(Request $request, ServiceOrder $order, OrderWorkEvidence $workEvidence): RedirectResponse
    {
        Gate::authorize('view', $order);
        abort_unless($workEvidence->service_order_id === $order->id, 404);

        $user = $request->user();
        $canConfirm = $user !== null
            && $user->is_active
            && (
                ($user->role === 'customer' && $order->customer_id === $user->id)
                || $user->role === 'operator'
            );

        abort_unless($canConfirm, 403);

        $validated = $request->validate([
            'confirmation_note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($workEvidence->customer_confirmed_at === null) {
            DB::transaction(function () use ($request, $order, $workEvidence, $user, $validated): void {
                $workEvidence->forceFill([
                    'customer_confirmed_at' => now(),
                    'confirmed_by' => $user->id,
                    'confirmation_note' => isset($validated['confirmation_note']) && trim((string) $validated['confirmation_note']) !== ''
                        ? trim((string) $validated['confirmation_note'])
                        : null,
                ])->save();

                DB::table('audit_logs')->insert([
                    'actor_id' => $user->id,
                    'event' => 'work_evidence.confirmed',
                    'auditable_type' => ServiceOrder::class,
                    'auditable_id' => $order->id,
                    'before' => null,
                    'after' => json_encode([
                        'evidence_id' => $workEvidence->id,
                        'confirmed_by' => $user->id,
                    ]),
                    'ip_address' => $request->ip(),
                    'created_at' => now(),
                ]);
            });
        }

        return back()->with('success', 'Konfirmasi hasil pengerjaan berhasil dicatat.');
    }
}

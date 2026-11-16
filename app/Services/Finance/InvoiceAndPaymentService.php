<?php

namespace App\Services\Finance;

use App\Models\Estimate;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceAndPaymentService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Resolve configurable manual payment methods supported by the platform.
     *
     * @return list<array{value: string, label: string}>
     */
    public function availablePaymentMethods(): array
    {
        $defaults = [
            ['value' => 'bank_transfer', 'label' => 'Transfer Bank'],
            ['value' => 'cash', 'label' => 'Tunai'],
            ['value' => 'qr', 'label' => 'QRIS'],
            ['value' => 'other', 'label' => 'Metode Manual Lainnya'],
        ];

        $stored = SystemSetting::getMany([
            'finance.custom_payment_methods' => '[]',
        ]);

        $decoded = json_decode($stored['finance.custom_payment_methods'] ?: '[]', true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        $known = array_column($defaults, 'value');
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = trim((string) ($entry['value'] ?? ''));
            $label = trim((string) ($entry['label'] ?? ''));
            if ($value === '' || $label === '' || in_array($value, $known, true)) {
                continue;
            }

            $defaults[] = ['value' => $value, 'label' => $label];
            $known[] = $value;
        }

        return $defaults;
    }

    /**
     * @return list<string>
     */
    public function allowedPaymentMethodValues(): array
    {
        return array_column($this->availablePaymentMethods(), 'value');
    }

    /**
     * Generate an invoice strictly from an approved quotation (blocks double conversion).
     *
     * @param  array{issue_date?: string|null, due_date?: string|null, notes?: string|null, issue_now?: bool}  $payload
     */
    public function createFromApprovedQuotation(User $actor, Estimate $estimate, array $payload = []): Invoice
    {
        $dueDays = $this->defaultDueDays();

        /** @var Invoice $invoice */
        $invoice = DB::transaction(function () use ($actor, $estimate, $payload, $dueDays): Invoice {
            $lockedEstimate = Estimate::query()
                ->with(['items', 'order.customer'])
                ->whereKey($estimate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEstimate->status === 'converted' || Invoice::query()->where('estimate_id', $lockedEstimate->id)->exists()) {
                throw ValidationException::withMessages([
                    'estimate_id' => 'Penawaran ini sudah pernah dikonversi menjadi tagihan.',
                ]);
            }

            if ($lockedEstimate->status !== 'approved') {
                throw ValidationException::withMessages([
                    'estimate_id' => 'Hanya penawaran berstatus disetujui yang dapat dikonversi menjadi tagihan.',
                ]);
            }

            $snapshot = is_array($lockedEstimate->approved_snapshot) ? $lockedEstimate->approved_snapshot : null;
            $snapshotItems = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : null;

            $rawLines = [];
            if ($snapshotItems !== null && $snapshotItems !== []) {
                foreach ($snapshotItems as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $rawLines[] = [
                        'item_type' => (string) ($item['item_type'] ?? 'labor'),
                        'label' => (string) ($item['label'] ?? 'Item Layanan'),
                        'description' => null,
                        'quantity' => (string) ($item['quantity'] ?? '1.00'),
                        'unit_price' => (int) ($item['unit_price'] ?? 0),
                        'unit_cost' => (int) ($item['estimated_unit_cost'] ?? 0),
                        'inventory_item_id' => isset($item['inventory_item_id']) ? (int) $item['inventory_item_id'] : null,
                        'service_order_spare_part_id' => isset($item['service_order_spare_part_id']) ? (int) $item['service_order_spare_part_id'] : null,
                    ];
                }
            } else {
                foreach ($lockedEstimate->items as $item) {
                    $rawLines[] = [
                        'item_type' => $item->item_type,
                        'label' => $item->label,
                        'description' => null,
                        'quantity' => (string) $item->quantity,
                        'unit_price' => $item->unit_price,
                        'unit_cost' => $item->estimated_unit_cost,
                        'inventory_item_id' => $item->inventory_item_id,
                        'service_order_spare_part_id' => $item->service_order_spare_part_id,
                    ];
                }
            }

            $discountAmount = (int) ($snapshot['discount_amount'] ?? $lockedEstimate->discount_amount);
            $taxRate = (string) ($snapshot['tax_rate'] ?? $lockedEstimate->tax_rate);
            $preparedLines = $this->prepareInvoiceLines($rawLines);
            $totals = FinanceMath::calculateDocumentTotals($preparedLines, $discountAmount, $taxRate);

            $order = $lockedEstimate->order;
            $issueDate = ! empty($payload['issue_date'])
                ? Carbon::parse((string) $payload['issue_date'])->toDateString()
                : now()->toDateString();
            $dueDate = ! empty($payload['due_date'])
                ? Carbon::parse((string) $payload['due_date'])->toDateString()
                : now()->addDays($dueDays)->toDateString();

            $customer = $lockedEstimate->customer ?? $order->customer;
            $invoice = Invoice::query()->create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'service_order_id' => $lockedEstimate->service_order_id,
                'estimate_id' => $lockedEstimate->id,
                'customer_id' => $lockedEstimate->customer_id ?? $order->customer_id,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->email ?? $customer?->email,
                'customer_phone' => $order->phone,
                'created_by' => $actor->id,
                'status' => 'draft',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'total_amount' => $totals['total'],
                'paid_amount' => 0,
                'notes' => isset($payload['notes']) && trim((string) $payload['notes']) !== ''
                    ? trim((string) $payload['notes'])
                    : $lockedEstimate->notes,
            ]);

            $this->persistInvoiceItems($invoice, $totals['lines']);

            $lockedEstimate->update([
                'status' => 'converted',
                'converted_at' => now(),
            ]);

            $this->writeAuditLog($actor, 'finance.invoice_created_from_quotation', $invoice, [
                'invoice_number' => $invoice->invoice_number,
                'estimate_id' => $lockedEstimate->id,
                'quotation_number' => $lockedEstimate->quotation_number,
                'total_amount' => $invoice->total_amount,
            ]);

            if ((bool) ($payload['issue_now'] ?? false)) {
                return $this->finalizeInvoiceIssue($actor, $invoice, $issueDate, $dueDate);
            }

            return $invoice->fresh(['items', 'order', 'estimate', 'customer']) ?? $invoice;
        });

        if ($invoice->status === 'issued') {
            $this->notifyCustomerInvoiceIssued($invoice);
        }

        return $invoice;
    }

    /**
     * Create an invoice through an authorized manual workflow without requiring a quotation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createManualInvoice(User $actor, array $payload): Invoice
    {
        $dueDays = $this->defaultDueDays();
        $defaultTaxRate = SystemSetting::getMany(['finance.default_tax_rate' => '0.00'])['finance.default_tax_rate'] ?: '0.00';

        /** @var Invoice $invoice */
        $invoice = DB::transaction(function () use ($actor, $payload, $dueDays, $defaultTaxRate): Invoice {
            $order = ! empty($payload['service_order_id'])
                ? ServiceOrder::query()->with('customer')->whereKey((string) $payload['service_order_id'])->firstOrFail()
                : null;

            $customerId = isset($payload['customer_id']) && $payload['customer_id']
                ? (int) $payload['customer_id']
                : $order?->customer_id;

            $customer = $customerId !== null ? User::query()->find($customerId) : null;
            $rawName = $payload['customer_name'] ?? ($order !== null ? $order->customer_name : ($customer !== null ? $customer->name : ''));
            $customerName = trim((string) $rawName);
            if ($customerName === '') {
                throw ValidationException::withMessages([
                    'customer_name' => 'Nama pelanggan wajib diisi untuk membuat tagihan manual.',
                ]);
            }

            /** @var list<array<string, mixed>> $rawItems */
            $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $preparedLines = $this->prepareInvoiceLines($rawItems);
            $totals = FinanceMath::calculateDocumentTotals(
                $preparedLines,
                max(0, (int) ($payload['discount_amount'] ?? 0)),
                $payload['tax_rate'] ?? $defaultTaxRate,
            );

            $issueDate = ! empty($payload['issue_date'])
                ? Carbon::parse((string) $payload['issue_date'])->toDateString()
                : now()->toDateString();
            $dueDate = ! empty($payload['due_date'])
                ? Carbon::parse((string) $payload['due_date'])->toDateString()
                : now()->addDays($dueDays)->toDateString();

            $fallbackEmail = $order !== null ? $order->email : ($customer !== null ? $customer->email : null);
            $fallbackPhone = $order !== null ? $order->phone : ($customer !== null ? $customer->phone : null);

            $invoice = Invoice::query()->create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'service_order_id' => $order?->id,
                'estimate_id' => null,
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'customer_email' => $payload['customer_email'] ?? $fallbackEmail,
                'customer_phone' => $payload['customer_phone'] ?? $fallbackPhone,
                'created_by' => $actor->id,
                'status' => 'draft',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'total_amount' => $totals['total'],
                'paid_amount' => 0,
                'notes' => isset($payload['notes']) && trim((string) $payload['notes']) !== '' ? trim((string) $payload['notes']) : null,
            ]);

            $this->persistInvoiceItems($invoice, $totals['lines']);

            $this->writeAuditLog($actor, 'finance.invoice_created', $invoice, [
                'invoice_number' => $invoice->invoice_number,
                'service_order_id' => $invoice->service_order_id,
                'total_amount' => $invoice->total_amount,
            ]);

            if ((bool) ($payload['issue_now'] ?? false)) {
                return $this->finalizeInvoiceIssue($actor, $invoice, $issueDate, $dueDate);
            }

            return $invoice->fresh(['items', 'order', 'customer']) ?? $invoice;
        });

        if ($invoice->status === 'issued') {
            $this->notifyCustomerInvoiceIssued($invoice);
        }

        return $invoice;
    }

    /**
     * Issue a draft invoice and lock its line items/totals via an immutable snapshot.
     */
    public function issueInvoice(User $actor, Invoice $invoice, ?string $issueDate = null, ?string $dueDate = null): Invoice
    {
        /** @var Invoice $issued */
        $issued = DB::transaction(function () use ($actor, $invoice, $issueDate, $dueDate): Invoice {
            $locked = Invoice::query()->with(['items', 'order'])->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya tagihan berstatus draf yang dapat diterbitkan.',
                ]);
            }

            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Tagihan harus memiliki minimal satu item sebelum diterbitkan.',
                ]);
            }

            return $this->finalizeInvoiceIssue($actor, $locked, $issueDate, $dueDate);
        });

        $this->notifyCustomerInvoiceIssued($issued);

        return $issued;
    }

    /**
     * Record a payment against an issued invoice with atomic duplicate & overpayment guards.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordInvoicePayment(User $actor, Invoice $invoice, array $payload): FinancialTransaction
    {
        $amount = (int) $payload['amount'];
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal pembayaran harus lebih besar dari nol.',
            ]);
        }

        $method = trim((string) $payload['payment_method']);
        if (! in_array($method, $this->allowedPaymentMethodValues(), true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Metode pembayaran yang dipilih tidak valid.',
            ]);
        }

        $reference = isset($payload['reference']) && trim((string) $payload['reference']) !== ''
            ? trim((string) $payload['reference'])
            : null;
        $idempotencyKey = isset($payload['idempotency_key']) && trim((string) $payload['idempotency_key']) !== ''
            ? trim((string) $payload['idempotency_key'])
            : null;

        $proofPath = null;
        if (($payload['proof'] ?? null) instanceof UploadedFile) {
            $proofPath = $payload['proof']->store('finance-proofs', 'local');
        }

        $verifyImmediately = $actor->role === 'operator' && (bool) ($payload['verify_immediately'] ?? false);

        /** @var array{transaction: FinancialTransaction, invoice: Invoice} $result */
        $result = DB::transaction(function () use ($actor, $invoice, $amount, $method, $reference, $idempotencyKey, $payload, $proofPath, $verifyImmediately): array {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['issued', 'partially_paid', 'overdue'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Pembayaran hanya dapat dicatat pada tagihan yang sudah diterbitkan dan belum lunas/dibatalkan.',
                ]);
            }

            if ($idempotencyKey !== null && FinancialTransaction::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'Permintaan pembayaran duplikat terdeteksi. Transaksi dengan kunci ini sudah tercatat.',
                ]);
            }

            if ($reference !== null) {
                $duplicateRef = FinancialTransaction::query()
                    ->where('invoice_id', $locked->id)
                    ->where('type', 'income')
                    ->whereIn('status', ['pending', 'verified'])
                    ->whereRaw('LOWER(reference) = ?', [mb_strtolower($reference)])
                    ->where('amount', $amount)
                    ->exists();

                if ($duplicateRef) {
                    throw ValidationException::withMessages([
                        'reference' => 'Pembayaran dengan referensi dan nominal yang sama sudah tercatat pada tagihan ini.',
                    ]);
                }
            }

            $reservedTotal = (int) FinancialTransaction::query()
                ->where('invoice_id', $locked->id)
                ->where('type', 'income')
                ->whereIn('status', ['pending', 'verified'])
                ->sum('amount');

            $remainingAllowed = max(0, $locked->total_amount - $reservedTotal);
            if ($amount > $remainingAllowed) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran melebihi sisa tagihan yang tersedia (Rp '.number_format($remainingAllowed, 0, ',', '.').').',
                ]);
            }

            $occurredAt = ! empty($payload['occurred_at']) ? Carbon::parse((string) $payload['occurred_at']) : now();
            $description = isset($payload['description']) && trim((string) $payload['description']) !== ''
                ? trim((string) $payload['description'])
                : "Pembayaran tagihan {$locked->invoice_number}";

            $transaction = FinancialTransaction::query()->create([
                'service_order_id' => $locked->service_order_id,
                'invoice_id' => $locked->id,
                'actor_id' => $actor->id,
                'verified_by' => $verifyImmediately ? $actor->id : null,
                'type' => 'income',
                'category' => 'invoice_payment',
                'amount' => $amount,
                'payment_method' => $method,
                'status' => $verifyImmediately ? 'verified' : 'pending',
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'proof_path' => $proofPath ?: null,
                'occurred_at' => $occurredAt,
                'verified_at' => $verifyImmediately ? now() : null,
            ]);

            $this->recalculateInvoicePaymentState($locked);

            $this->writeAuditLog($actor, 'finance.payment_recorded', $transaction, [
                'invoice_id' => $locked->id,
                'invoice_number' => $locked->invoice_number,
                'amount' => $transaction->amount,
                'payment_method' => $transaction->payment_method,
                'status' => $transaction->status,
            ]);

            return [
                'transaction' => $transaction->fresh(['invoice', 'actor', 'verifier']) ?? $transaction,
                'invoice' => $locked->fresh() ?? $locked,
            ];
        });

        $transaction = $result['transaction'];
        $updatedInvoice = $result['invoice'];

        if ($transaction->status === 'pending') {
            $operators = User::query()->where('role', 'operator')->where('is_active', true)->get();
            $this->notifications->notifyUsers(
                $operators,
                'finance.payment_recorded',
                "Pembayaran baru menunggu verifikasi ({$updatedInvoice->invoice_number})",
                'Nominal Rp '.number_format($transaction->amount, 0, ',', '.')." dicatat oleh {$actor->name}.",
                "/finance/invoices/{$updatedInvoice->id}",
                [
                    'invoice_id' => $updatedInvoice->id,
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount,
                ],
                dedupeKey: "finance-payment-recorded-{$transaction->id}",
            );
        } elseif ($updatedInvoice->customer !== null) {
            $this->notifications->notifyUsers(
                [$updatedInvoice->customer],
                'finance.transaction_verified',
                "Pembayaran tagihan {$updatedInvoice->invoice_number} terverifikasi",
                'Pembayaran sebesar Rp '.number_format($transaction->amount, 0, ',', '.').' telah diverifikasi.',
                "/finance/invoices/{$updatedInvoice->id}",
                [
                    'invoice_id' => $updatedInvoice->id,
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount,
                ],
                dedupeKey: "finance-tx-verified-{$transaction->id}",
            );
        }

        return $transaction;
    }

    /**
     * Verify a pending financial transaction and synchronize linked invoice balance atomically.
     */
    public function verifyTransaction(User $actor, FinancialTransaction $transaction): FinancialTransaction
    {
        /** @var FinancialTransaction $verified */
        $verified = DB::transaction(function () use ($actor, $transaction): FinancialTransaction {
            $lockedTx = FinancialTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($lockedTx->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya transaksi berstatus pending yang dapat diverifikasi.',
                ]);
            }

            if ($lockedTx->invoice_id !== null && $lockedTx->type === 'income') {
                $lockedInvoice = Invoice::query()->whereKey($lockedTx->invoice_id)->lockForUpdate()->firstOrFail();

                if ($lockedInvoice->status === 'cancelled') {
                    throw ValidationException::withMessages([
                        'invoice_id' => 'Transaksi tidak dapat diverifikasi karena tagihan terkait telah dibatalkan.',
                    ]);
                }

                $remaining = max(0, $lockedInvoice->total_amount - $lockedInvoice->paid_amount);
                if ($lockedTx->amount > $remaining) {
                    throw ValidationException::withMessages([
                        'amount' => 'Verifikasi dibatalkan karena akan membuat total pembayaran melebihi nilai tagihan.',
                    ]);
                }

                $lockedTx->update([
                    'status' => 'verified',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ]);

                $this->recalculateInvoicePaymentState($lockedInvoice);
            } else {
                $lockedTx->update([
                    'status' => 'verified',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ]);
            }

            $this->writeAuditLog($actor, 'finance.transaction_verified', $lockedTx, [
                'status' => 'verified',
                'amount' => $lockedTx->amount,
                'invoice_id' => $lockedTx->invoice_id,
            ]);

            return $lockedTx->fresh(['order.customer', 'invoice.customer', 'actor', 'verifier']) ?? $lockedTx;
        });

        $recipient = $verified->invoice->customer ?? $verified->order?->customer;
        if ($recipient !== null && $verified->type === 'income') {
            $this->notifications->notifyUsers(
                [$recipient],
                'finance.transaction_verified',
                'Pembayaran terverifikasi',
                'Pembayaran Rp '.number_format($verified->amount, 0, ',', '.').' telah diverifikasi operator.',
                $verified->invoice_id !== null
                    ? "/finance/invoices/{$verified->invoice_id}"
                    : ($verified->service_order_id !== null ? "/orders/{$verified->service_order_id}" : '/finance'),
                [
                    'transaction_id' => $verified->id,
                    'invoice_id' => $verified->invoice_id,
                    'service_order_id' => $verified->service_order_id,
                    'amount' => $verified->amount,
                ],
                dedupeKey: "finance-tx-verified-{$verified->id}",
            );
        }

        return $verified;
    }

    /**
     * Reject a pending financial transaction with an audit-logged reason.
     */
    public function rejectTransaction(User $actor, FinancialTransaction $transaction, string $reason): FinancialTransaction
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan penolakan transaksi wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($actor, $transaction, $cleanReason): FinancialTransaction {
            $lockedTx = FinancialTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($lockedTx->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya transaksi berstatus pending yang dapat ditolak.',
                ]);
            }

            $lockedTx->update([
                'status' => 'rejected',
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $cleanReason,
            ]);

            if ($lockedTx->invoice_id !== null) {
                $lockedInvoice = Invoice::query()->whereKey($lockedTx->invoice_id)->lockForUpdate()->first();
                if ($lockedInvoice !== null) {
                    $this->recalculateInvoicePaymentState($lockedInvoice);
                }
            }

            $this->writeAuditLog($actor, 'finance.transaction_rejected', $lockedTx, [
                'status' => 'rejected',
                'reason' => $cleanReason,
                'invoice_id' => $lockedTx->invoice_id,
            ]);

            return $lockedTx->fresh(['invoice', 'actor', 'rejecter']) ?? $lockedTx;
        });
    }

    /**
     * Cancel/void an unpaid invoice with explicit audit tracking.
     */
    public function cancelInvoice(User $actor, Invoice $invoice, string $reason): Invoice
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan tagihan wajib diisi.',
            ]);
        }

        /** @var Invoice $cancelled */
        $cancelled = DB::transaction(function () use ($actor, $invoice, $cleanReason): Invoice {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => 'Tagihan ini sudah dibatalkan.',
                ]);
            }

            $hasActivePayments = FinancialTransaction::query()
                ->where('invoice_id', $locked->id)
                ->where('type', 'income')
                ->whereIn('status', ['pending', 'verified'])
                ->exists();

            if ($locked->paid_amount > 0 || $hasActivePayments) {
                throw ValidationException::withMessages([
                    'status' => 'Tagihan yang sudah memiliki pembayaran terverifikasi atau menunggu verifikasi tidak dapat dibatalkan.',
                ]);
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'voided_by' => $actor->id,
                'cancellation_reason' => $cleanReason,
            ]);

            $this->writeAuditLog($actor, 'finance.invoice_cancelled', $locked, [
                'invoice_number' => $locked->invoice_number,
                'reason' => $cleanReason,
            ]);

            return $locked->fresh(['items', 'order', 'customer', 'voider']) ?? $locked;
        });

        if ($cancelled->customer !== null) {
            $this->notifications->notifyUsers(
                [$cancelled->customer],
                'finance.invoice_cancelled',
                "Tagihan {$cancelled->invoice_number} dibatalkan",
                "Tagihan telah dibatalkan oleh tim operasional. Alasan: {$cleanReason}.",
                "/finance/invoices/{$cancelled->id}",
                [
                    'invoice_id' => $cancelled->id,
                    'invoice_number' => $cancelled->invoice_number,
                ],
                dedupeKey: "finance-invoice-cancelled-{$cancelled->id}",
            );
        }

        return $cancelled;
    }

    /**
     * Recompute an invoice's paid_amount and payment status solely from verified transactions.
     */
    public function recalculateInvoicePaymentState(Invoice $invoice): void
    {
        $verifiedPaid = (int) FinancialTransaction::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'income')
            ->where('status', 'verified')
            ->sum('amount');

        if (in_array($invoice->status, ['draft', 'cancelled'], true)) {
            $invoice->update(['paid_amount' => $verifiedPaid]);

            return;
        }

        if ($verifiedPaid >= $invoice->total_amount && $invoice->total_amount > 0) {
            $invoice->update([
                'paid_amount' => $verifiedPaid,
                'status' => 'paid',
                'paid_at' => $invoice->paid_at ?? now(),
            ]);

            return;
        }

        $invoice->update([
            'paid_amount' => $verifiedPaid,
            'status' => $verifiedPaid > 0 ? 'partially_paid' : 'issued',
            'paid_at' => null,
        ]);
    }

    /**
     * Sanitize an invoice's snapshot when displayed to a customer (never expose internal costs).
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public function sanitizeSnapshotForRole(?array $snapshot, string $role): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        if (! in_array($role, ['customer', 'warehouse'], true)) {
            return $snapshot;
        }

        $items = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
        $snapshot['items'] = array_map(static function (mixed $item): array {
            if (! is_array($item)) {
                return [];
            }

            unset($item['unit_cost']);

            return $item;
        }, $items);

        return $snapshot;
    }

    private function finalizeInvoiceIssue(User $actor, Invoice $invoice, ?string $issueDate = null, ?string $dueDate = null): Invoice
    {
        $invoice->loadMissing(['items', 'order']);

        $resolvedIssueDate = $issueDate ?? $invoice->issue_date?->toDateString() ?? now()->toDateString();
        $resolvedDueDate = $dueDate ?? $invoice->due_date?->toDateString() ?? now()->addDays($this->defaultDueDays())->toDateString();
        $issuedAt = now();

        $snapshot = [
            'invoice_number' => $invoice->invoice_number,
            'service_order_id' => $invoice->service_order_id,
            'ticket_number' => $invoice->order?->ticket_number,
            'estimate_id' => $invoice->estimate_id,
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer_name,
            'customer_email' => $invoice->customer_email,
            'customer_phone' => $invoice->customer_phone,
            'issue_date' => $resolvedIssueDate,
            'due_date' => $resolvedDueDate,
            'subtotal' => $invoice->subtotal,
            'discount_amount' => $invoice->discount_amount,
            'tax_rate' => (string) $invoice->tax_rate,
            'tax_amount' => $invoice->tax_amount,
            'total_amount' => $invoice->total_amount,
            'notes' => $invoice->notes,
            'issued_at' => $issuedAt->toIso8601String(),
            'issued_by' => $actor->id,
            'issued_by_name' => $actor->name,
            'items' => $invoice->items->map(static fn ($item): array => [
                'id' => $item->id,
                'item_type' => $item->item_type,
                'label' => $item->label,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit_price' => $item->unit_price,
                'unit_cost' => $item->unit_cost,
                'line_total' => $item->line_total,
                'inventory_item_id' => $item->inventory_item_id,
                'service_order_spare_part_id' => $item->service_order_spare_part_id,
                'sort_order' => $item->sort_order,
            ])->values()->all(),
        ];

        $invoice->update([
            'status' => 'issued',
            'issue_date' => $resolvedIssueDate,
            'due_date' => $resolvedDueDate,
            'issued_by' => $actor->id,
            'issued_at' => $issuedAt,
            'issued_snapshot' => $snapshot,
        ]);

        $this->writeAuditLog($actor, 'finance.invoice_issued', $invoice, [
            'invoice_number' => $invoice->invoice_number,
            'total_amount' => $invoice->total_amount,
            'due_date' => $resolvedDueDate,
        ]);

        return $invoice->fresh(['items', 'order', 'estimate', 'customer', 'issuer']) ?? $invoice;
    }

    /**
     * @param  list<array<string, mixed>>  $rawItems
     * @return list<array<string, mixed>>
     */
    private function prepareInvoiceLines(array $rawItems): array
    {
        $prepared = [];

        foreach ($rawItems as $index => $item) {
            $inventoryItemId = isset($item['inventory_item_id']) && $item['inventory_item_id'] ? (int) $item['inventory_item_id'] : null;
            $sparePartId = isset($item['service_order_spare_part_id']) && $item['service_order_spare_part_id'] ? (int) $item['service_order_spare_part_id'] : null;

            $sparePart = $sparePartId !== null ? ServiceOrderSparePart::query()->find($sparePartId) : null;
            if ($sparePart !== null && $inventoryItemId === null) {
                $inventoryItemId = $sparePart->inventory_item_id;
            }

            $inventoryItem = $inventoryItemId !== null ? InventoryItem::query()->find($inventoryItemId) : null;
            $type = (string) ($item['item_type'] ?? ($inventoryItemId !== null || $sparePartId !== null ? 'spare_part' : 'labor'));

            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '' && $inventoryItem !== null) {
                $label = $inventoryItem->name;
            }

            $unitCost = isset($item['unit_cost']) ? max(0, (int) $item['unit_cost']) : 0;
            if ($unitCost === 0 && $sparePart !== null) {
                $unitCost = $sparePart->unit_cost;
            } elseif ($unitCost === 0 && $inventoryItem !== null) {
                $unitCost = $inventoryItem->unit_cost;
            }

            $prepared[] = [
                'item_type' => $type,
                'label' => $label,
                'description' => isset($item['description']) && trim((string) $item['description']) !== '' ? trim((string) $item['description']) : null,
                'quantity' => $item['quantity'],
                'unit_price' => (int) $item['unit_price'],
                'unit_cost' => $unitCost,
                'inventory_item_id' => $inventoryItemId,
                'service_order_spare_part_id' => $sparePartId,
                'sort_order' => $index,
            ];
        }

        return $prepared;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function persistInvoiceItems(Invoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            $invoice->items()->create([
                'item_type' => $line['item_type'],
                'label' => $line['label'],
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'unit_cost' => $line['unit_cost'] ?? 0,
                'line_total' => $line['line_total'],
                'inventory_item_id' => $line['inventory_item_id'] ?? null,
                'service_order_spare_part_id' => $line['service_order_spare_part_id'] ?? null,
                'sort_order' => $line['sort_order'],
            ]);
        }
    }

    private function defaultDueDays(): int
    {
        $stored = SystemSetting::getMany(['finance.invoice_due_days' => '7'])['finance.invoice_due_days'];

        return max(1, (int) ($stored ?: 7));
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('ym').'-';
        $latest = Invoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/^INV-\d{4}-(\d+)/', $latest, $matches) === 1) {
            $seq = ((int) $matches[1]) + 1;
        }

        do {
            $candidate = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $exists = Invoice::query()->where('invoice_number', $candidate)->exists();
            $seq++;
        } while ($exists);

        return $candidate;
    }

    private function notifyCustomerInvoiceIssued(Invoice $invoice): void
    {
        if ($invoice->customer === null) {
            return;
        }

        $totalFormatted = 'Rp '.number_format($invoice->total_amount, 0, ',', '.');
        $dueText = $invoice->due_date?->translatedFormat('d M Y') ?? '-';

        $this->notifications->notifyUsers(
            [$invoice->customer],
            'finance.invoice_issued',
            "Tagihan {$invoice->invoice_number} diterbitkan",
            "Tagihan senilai {$totalFormatted} (jatuh tempo {$dueText}) telah tersedia.",
            "/finance/invoices/{$invoice->id}",
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total_amount' => $invoice->total_amount,
            ],
            dedupeKey: "finance-invoice-issued-{$invoice->id}",
        );
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>|null  $before
     */
    private function writeAuditLog(User $actor, string $event, Invoice|FinancialTransaction $model, array $after, ?array $before = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id,
            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => (string) $model->getKey(),
            'before' => $before !== null ? json_encode($before) : null,
            'after' => json_encode($after),
            'created_at' => now(),
        ]);
    }
}

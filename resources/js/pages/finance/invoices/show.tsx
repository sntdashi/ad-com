import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    BadgeCheck,
    Ban,
    CreditCard,
    ExternalLink,
    FileCheck2,
    Lock,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    cancel as invoiceCancel,
    index as invoicesIndex,
    issue as invoiceIssue,
} from '@/routes/finance/invoices';
import { store as invoicePaymentStore } from '@/routes/finance/invoices/payments';
import { show as quotationShow } from '@/routes/finance/quotations';
import {
    reject as rejectTransaction,
    verify as verifyTransaction,
} from '@/routes/finance/transactions';
import { show as orderShow } from '@/routes/orders';

type InvoiceItem = {
    id: number;
    item_type: string;
    label: string;
    description: string | null;
    quantity: string;
    unit_price: number;
    line_total: number;
    sku: string | null;
    unit_cost?: number;
};

type PaymentRecord = {
    id: number;
    amount: number;
    payment_method: string;
    status: string;
    reference: string | null;
    description: string | null;
    occurred_at: string;
    actor_name: string;
    verifier_name: string | null;
    verified_at: string | null;
    rejecter_name: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    proof_url: string | null;
    can_verify: boolean;
    can_reject: boolean;
};

type InvoiceDetail = {
    id: string;
    invoice_number: string;
    service_order_id: string | null;
    ticket_number: string | null;
    estimate_id: string | null;
    quotation_number: string | null;
    customer_name: string;
    customer_email: string | null;
    customer_phone: string | null;
    status: string;
    issue_date_label: string | null;
    due_date_label: string | null;
    subtotal: number;
    discount_amount: number;
    tax_rate: string;
    tax_amount: number;
    total_amount: number;
    paid_amount: number;
    pending_amount: number;
    outstanding_balance: number;
    notes: string | null;
    issued_at: string | null;
    issued_by_name: string | null;
    paid_at: string | null;
    cancelled_at: string | null;
    voided_by_name: string | null;
    cancellation_reason: string | null;
    issued_snapshot: Record<string, unknown> | null;
    items: InvoiceItem[];
    payments: PaymentRecord[];
};

type AuditEntry = {
    id: number;
    event: string;
    actor_name: string;
    created_at: string;
};

const money = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const statusMeta: Record<string, { label: string; className: string }> = {
    draft: {
        label: 'Draf (Belum Terbit)',
        className: 'border border-border bg-muted text-muted-foreground',
    },
    issued: {
        label: 'Terbit · Menunggu Pembayaran',
        className: 'status-pill-info',
    },
    partially_paid: {
        label: 'Dibayar Sebagian',
        className: 'status-pill-warning',
    },
    paid: { label: 'Lunas', className: 'status-pill-success' },
    overdue: {
        label: 'Jatuh Tempo (Overdue)',
        className: 'status-pill-danger',
    },
    cancelled: {
        label: 'Dibatalkan',
        className: 'border border-border bg-muted text-muted-foreground',
    },
};

function makeKey(prefix: string): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return `${prefix}-${crypto.randomUUID()}`;
    }

    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

export default function InvoiceShowPage({
    isInternal,
    paymentMethods,
    invoice,
    permissions,
    auditLogs,
}: {
    isInternal: boolean;
    paymentMethods: Array<{ value: string; label: string }>;
    invoice: InvoiceDetail;
    permissions: {
        can_issue: boolean;
        can_record_payment: boolean;
        can_cancel: boolean;
        can_verify_immediately: boolean;
    };
    auditLogs: AuditEntry[];
}) {
    const [showPaymentForm, setShowPaymentForm] = useState(false);
    const [showCancelBox, setShowCancelBox] = useState(false);
    const [rejectTxId, setRejectTxId] = useState<number | null>(null);
    const [rejectTxReason, setRejectTxReason] = useState('');

    const remainingPayableCapacity = Math.max(
        0,
        invoice.total_amount - invoice.paid_amount - invoice.pending_amount,
    );

    const paymentForm = useForm<{
        amount: string;
        payment_method: string;
        reference: string;
        idempotency_key: string;
        description: string;
        occurred_at: string;
        proof: File | null;
        verify_immediately: boolean;
    }>({
        amount: String(
            remainingPayableCapacity > 0
                ? remainingPayableCapacity
                : invoice.outstanding_balance,
        ),
        payment_method: paymentMethods[0]?.value ?? 'bank_transfer',
        reference: '',
        idempotency_key: makeKey('pay'),
        description: '',
        occurred_at: new Date().toISOString().slice(0, 10),
        proof: null,
        verify_immediately: false,
    });

    const cancelForm = useForm({ reason: '' });

    const badge = statusMeta[invoice.status] ?? {
        label: invoice.status,
        className: 'border border-border bg-muted text-muted-foreground',
    };
    const methodMap = Object.fromEntries(
        paymentMethods.map((m) => [m.value, m.label]),
    );

    function submitPayment(event: FormEvent) {
        event.preventDefault();
        paymentForm.post(invoicePaymentStore.url(invoice.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setShowPaymentForm(false);
                paymentForm.reset('reference', 'description', 'proof');
                paymentForm.setData('idempotency_key', makeKey('pay'));
            },
        });
    }

    function submitCancel(event: FormEvent) {
        event.preventDefault();
        cancelForm.post(invoiceCancel.url(invoice.id), {
            preserveScroll: true,
            onSuccess: () => setShowCancelBox(false),
        });
    }

    function submitRejectTx(event: FormEvent) {
        event.preventDefault();
        if (rejectTxId === null || rejectTxReason.trim() === '') {
            return;
        }
        router.patch(
            rejectTransaction.url(rejectTxId),
            { reason: rejectTxReason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectTxId(null);
                    setRejectTxReason('');
                },
            },
        );
    }

    return (
        <>
            <Head title={`Tagihan ${invoice.invoice_number}`} />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-6xl space-y-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link
                            href={invoicesIndex.url()}
                            className="border-border bg-card text-foreground hover:bg-muted inline-flex items-center gap-2 rounded-full border px-4 py-2 text-xs font-black shadow-xs transition"
                        >
                            <ArrowLeft className="size-3.5" />
                            Semua Tagihan
                        </Link>
                        <div className="flex flex-wrap gap-2">
                            {invoice.estimate_id &&
                                invoice.quotation_number && (
                                    <Link
                                        href={quotationShow.url(
                                            invoice.estimate_id,
                                        )}
                                        className="border-border bg-card text-foreground hover:bg-muted rounded-full border px-4 py-2 text-xs font-black shadow-xs transition"
                                    >
                                        Penawaran {invoice.quotation_number}
                                    </Link>
                                )}
                            {invoice.service_order_id &&
                                invoice.ticket_number && (
                                    <Link
                                        href={orderShow.url(
                                            invoice.service_order_id,
                                        )}
                                        className="border-border bg-card text-foreground hover:bg-muted rounded-full border px-4 py-2 text-xs font-black shadow-xs transition"
                                    >
                                        Tiket {invoice.ticket_number}
                                    </Link>
                                )}
                        </div>
                    </div>

                    <header className="liquid-panel-dark rounded-[28px] p-6 text-white sm:p-8">
                        <div className="flex flex-wrap items-start justify-between gap-6">
                            <div>
                                <div className="flex flex-wrap items-center gap-2.5">
                                    <p className="text-cyan text-xs font-black tracking-[.15em] uppercase">
                                        Invoice
                                    </p>
                                    <span
                                        className={`rounded-full px-3 py-1 text-[11px] font-black ${badge.className}`}
                                    >
                                        {badge.label}
                                    </span>
                                </div>
                                <h1 className="mt-2 text-3xl font-black tracking-[-.04em]">
                                    {invoice.invoice_number}
                                </h1>
                                <p className="mt-2 text-sm text-white/70">
                                    Ditagihkan kepada{' '}
                                    <strong>{invoice.customer_name}</strong>
                                    {invoice.due_date_label
                                        ? ` · Jatuh Tempo ${invoice.due_date_label}`
                                        : ''}
                                </p>
                            </div>

                            <div className="grid grid-cols-3 gap-4 text-right">
                                <div>
                                    <p className="text-[10px] font-bold text-white/55 uppercase">
                                        Total Tagihan
                                    </p>
                                    <p className="mt-1 text-lg font-black">
                                        {money.format(invoice.total_amount)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-[10px] font-bold text-emerald-300 uppercase">
                                        Terbayar (Verified)
                                    </p>
                                    <p className="mt-1 text-lg font-black text-emerald-300">
                                        {money.format(invoice.paid_amount)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-[10px] font-bold text-amber-300 uppercase">
                                        Sisa Piutang
                                    </p>
                                    <p className="mt-1 text-lg font-black text-amber-300">
                                        {money.format(
                                            invoice.outstanding_balance,
                                        )}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-white/12 pt-5">
                            {permissions.can_issue && (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            invoiceIssue.url(invoice.id),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="rounded-full bg-white text-xs font-black text-slate-900 hover:bg-white/90"
                                >
                                    <FileCheck2 className="mr-1.5 size-3.5" />
                                    Terbitkan & Kunci Tagihan
                                </Button>
                            )}
                            {permissions.can_record_payment && (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        setShowPaymentForm((v) => !v)
                                    }
                                    className="rounded-full bg-emerald-500 text-xs font-black text-white hover:bg-emerald-600"
                                >
                                    <CreditCard className="mr-1.5 size-3.5" />
                                    Catat Pembayaran
                                </Button>
                            )}
                            {permissions.can_cancel && (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() => setShowCancelBox((v) => !v)}
                                    className="rounded-full text-xs font-black"
                                >
                                    <Ban className="mr-1.5 size-3.5" />
                                    Batalkan Tagihan
                                </Button>
                            )}
                        </div>
                    </header>

                    {showPaymentForm && permissions.can_record_payment && (
                        <form
                            onSubmit={submitPayment}
                            className="liquid-panel rounded-[26px] p-6"
                        >
                            <h2 className="text-ink text-lg font-black">
                                Catat Pembayaran Tagihan
                            </h2>
                            <p className="text-ink/55 mt-1 text-xs">
                                Maksimal nominal baru yang dapat dicatat saat
                                ini:{' '}
                                <strong>
                                    {money.format(remainingPayableCapacity)}
                                </strong>
                                {invoice.pending_amount > 0
                                    ? ` (${money.format(invoice.pending_amount)} sedang menunggu verifikasi operator).`
                                    : '.'}
                            </p>
                            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Nominal (IDR)
                                    </label>
                                    <Input
                                        type="number"
                                        min={1}
                                        max={Math.max(
                                            1,
                                            remainingPayableCapacity,
                                        )}
                                        value={paymentForm.data.amount}
                                        onChange={(e) =>
                                            paymentForm.setData(
                                                'amount',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={paymentForm.errors.amount}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Metode
                                    </label>
                                    <select
                                        value={paymentForm.data.payment_method}
                                        onChange={(e) =>
                                            paymentForm.setData(
                                                'payment_method',
                                                e.target.value,
                                            )
                                        }
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-xs font-bold"
                                    >
                                        {paymentMethods.map((m) => (
                                            <option
                                                key={m.value}
                                                value={m.value}
                                            >
                                                {m.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={
                                            paymentForm.errors.payment_method
                                        }
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Referensi / No. Bukti
                                    </label>
                                    <Input
                                        value={paymentForm.data.reference}
                                        onChange={(e) =>
                                            paymentForm.setData(
                                                'reference',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="TRF-001 / KWITANSI-01"
                                    />
                                    <InputError
                                        message={paymentForm.errors.reference}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Tanggal Bayar
                                    </label>
                                    <Input
                                        type="date"
                                        value={paymentForm.data.occurred_at}
                                        onChange={(e) =>
                                            paymentForm.setData(
                                                'occurred_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Catatan
                                    </label>
                                    <Input
                                        value={paymentForm.data.description}
                                        onChange={(e) =>
                                            paymentForm.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="DP / Pelunasan..."
                                    />
                                </div>
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Bukti Bayar (Opsional)
                                    </label>
                                    <Input
                                        type="file"
                                        accept="image/*,.pdf"
                                        onChange={(e) =>
                                            paymentForm.setData(
                                                'proof',
                                                e.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={paymentForm.errors.proof}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <InputError
                                message={paymentForm.errors.idempotency_key}
                                className="mt-2"
                            />

                            <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
                                {permissions.can_verify_immediately ? (
                                    <label className="text-foreground flex items-center gap-2 text-xs font-bold">
                                        <input
                                            type="checkbox"
                                            checked={
                                                paymentForm.data
                                                    .verify_immediately
                                            }
                                            onChange={(e) =>
                                                paymentForm.setData(
                                                    'verify_immediately',
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        Verifikasi langsung (khusus Operator)
                                    </label>
                                ) : (
                                    <span className="text-muted-foreground text-xs">
                                        Pembayaran menunggu verifikasi operator.
                                    </span>
                                )}
                                <Button
                                    type="submit"
                                    disabled={paymentForm.processing}
                                    className="rounded-full px-6 text-xs font-black"
                                >
                                    {paymentForm.processing
                                        ? 'Menyimpan...'
                                        : 'Simpan Pembayaran'}
                                </Button>
                            </div>
                        </form>
                    )}

                    {showCancelBox && permissions.can_cancel && (
                        <form
                            onSubmit={submitCancel}
                            className="liquid-panel rounded-[24px] border border-rose-200 p-6"
                        >
                            <h2 className="text-sm font-black text-rose-800">
                                Batalkan Tagihan {invoice.invoice_number}
                            </h2>
                            <div className="mt-3 flex flex-wrap gap-3">
                                <Input
                                    value={cancelForm.data.reason}
                                    onChange={(e) =>
                                        cancelForm.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Alasan pembatalan resmi (wajib dicatat di audit log)..."
                                    required
                                    className="flex-1"
                                />
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={cancelForm.processing}
                                    className="rounded-full text-xs font-black"
                                >
                                    Konfirmasi Pembatalan
                                </Button>
                            </div>
                            <InputError
                                message={cancelForm.errors.reason}
                                className="mt-2"
                            />
                        </form>
                    )}

                    {invoice.status === 'cancelled' &&
                        invoice.cancellation_reason && (
                            <div className="rounded-[22px] border border-rose-500/25 bg-rose-500/10 p-5 text-xs text-rose-800 dark:text-rose-200">
                                <p className="font-black">
                                    Dibatalkan pada {invoice.cancelled_at} oleh{' '}
                                    {invoice.voided_by_name}
                                </p>
                                <p className="mt-1">
                                    {invoice.cancellation_reason}
                                </p>
                            </div>
                        )}

                    <div className="grid gap-6 lg:grid-cols-12">
                        <div className="space-y-6 lg:col-span-8">
                            <section className="liquid-panel rounded-[26px] p-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            {invoice.issued_snapshot !== null
                                                ? 'Immutable issued snapshot'
                                                : 'Draft invoice items'}
                                        </p>
                                        <h2 className="text-ink mt-1 text-lg font-black">
                                            Rincian Tagihan
                                        </h2>
                                    </div>
                                    {invoice.issued_snapshot !== null && (
                                        <span className="status-pill-info inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[11px] font-black">
                                            <Lock className="size-3" />
                                            Terkunci saat Terbit (
                                            {invoice.issued_at})
                                        </span>
                                    )}
                                </div>

                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead>
                                            <tr className="border-border text-muted-foreground border-b text-[11px] font-black uppercase">
                                                <th className="py-2.5 pr-3">
                                                    Deskripsi
                                                </th>
                                                <th className="py-2.5 pr-3 text-right">
                                                    Qty
                                                </th>
                                                <th className="py-2.5 pr-3 text-right">
                                                    Harga
                                                </th>
                                                {isInternal && (
                                                    <th className="py-2.5 pr-3 text-right">
                                                        Modal
                                                    </th>
                                                )}
                                                <th className="py-2.5 text-right">
                                                    Subtotal
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {invoice.items.map((item) => (
                                                <tr key={item.id}>
                                                    <td className="py-3 pr-3">
                                                        <p className="text-foreground font-black">
                                                            {item.label}
                                                        </p>
                                                        {item.description && (
                                                            <p className="text-muted-foreground text-[11px]">
                                                                {
                                                                    item.description
                                                                }
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="py-3 pr-3 text-right font-bold">
                                                        {item.quantity}
                                                    </td>
                                                    <td className="py-3 pr-3 text-right font-bold">
                                                        {money.format(
                                                            item.unit_price,
                                                        )}
                                                    </td>
                                                    {isInternal && (
                                                        <td className="text-muted-foreground py-3 pr-3 text-right">
                                                            {money.format(
                                                                item.unit_cost ??
                                                                    0,
                                                            )}
                                                        </td>
                                                    )}
                                                    <td className="text-foreground py-3 text-right font-black">
                                                        {money.format(
                                                            item.line_total,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="border-border mt-4 space-y-1.5 border-t pt-4 text-xs">
                                    <div className="text-muted-foreground flex justify-between">
                                        <span>Subtotal</span>
                                        <span className="text-foreground font-bold">
                                            {money.format(invoice.subtotal)}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground flex justify-between">
                                        <span>Diskon</span>
                                        <span>
                                            -{' '}
                                            {money.format(
                                                invoice.discount_amount,
                                            )}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground flex justify-between">
                                        <span>Pajak ({invoice.tax_rate}%)</span>
                                        <span>
                                            + {money.format(invoice.tax_amount)}
                                        </span>
                                    </div>
                                    <div className="border-border text-foreground flex justify-between border-t pt-2 text-base font-black">
                                        <span>Total Tagihan</span>
                                        <span>
                                            {money.format(invoice.total_amount)}
                                        </span>
                                    </div>
                                </div>
                            </section>

                            <section className="liquid-panel rounded-[26px] p-6">
                                <h2 className="text-ink text-lg font-black">
                                    Riwayat Pembayaran (
                                    {invoice.payments.length})
                                </h2>

                                {rejectTxId !== null && (
                                    <form
                                        onSubmit={submitRejectTx}
                                        className="mt-4 rounded-2xl border border-rose-500/25 bg-rose-500/10 p-4"
                                    >
                                        <p className="text-xs font-black text-rose-800 dark:text-rose-200">
                                            Tolak Pembayaran #{rejectTxId}
                                        </p>
                                        <div className="mt-2 flex gap-2">
                                            <Input
                                                value={rejectTxReason}
                                                onChange={(e) =>
                                                    setRejectTxReason(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Alasan penolakan pembayaran..."
                                                required
                                                className="h-9 flex-1 bg-white text-xs"
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="destructive"
                                                className="h-9 rounded-xl text-xs font-black"
                                            >
                                                Tolak
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setRejectTxId(null)
                                                }
                                                className="h-9 text-xs"
                                            >
                                                Batal
                                            </Button>
                                        </div>
                                    </form>
                                )}

                                <div className="mt-4 space-y-3">
                                    {invoice.payments.map((tx) => (
                                        <div
                                            key={tx.id}
                                            className="surface-tile flex flex-wrap items-center justify-between gap-4 rounded-2xl p-4 text-xs"
                                        >
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-foreground font-black">
                                                        {money.format(
                                                            tx.amount,
                                                        )}
                                                    </span>
                                                    <span className="border-border bg-muted text-muted-foreground rounded-full border px-2 py-0.5 text-[10px] font-bold">
                                                        {methodMap[
                                                            tx.payment_method
                                                        ] ?? tx.payment_method}
                                                    </span>
                                                    <span
                                                        className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${
                                                            tx.status ===
                                                            'verified'
                                                                ? 'status-pill-success'
                                                                : tx.status ===
                                                                    'rejected'
                                                                  ? 'status-pill-danger'
                                                                  : 'status-pill-warning'
                                                        }`}
                                                    >
                                                        {tx.status}
                                                    </span>
                                                </div>
                                                <p className="text-ink/55 mt-1 text-[11px]">
                                                    {tx.occurred_at} · Ref:{' '}
                                                    {tx.reference ?? '-'} ·
                                                    Pencatat: {tx.actor_name}
                                                    {tx.verifier_name
                                                        ? ` · Verifikasi: ${tx.verifier_name}`
                                                        : ''}
                                                    {tx.rejection_reason
                                                        ? ` · Alasan tolak: ${tx.rejection_reason}`
                                                        : ''}
                                                </p>
                                                {tx.proof_url && (
                                                    <a
                                                        href={tx.proof_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="text-cyan mt-1 inline-flex items-center gap-1 font-bold hover:underline"
                                                    >
                                                        Unduh Bukti Pembayaran
                                                        <ExternalLink className="size-3" />
                                                    </a>
                                                )}
                                            </div>

                                            <div className="flex gap-2">
                                                {tx.can_verify && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.patch(
                                                                verifyTransaction.url(
                                                                    tx.id,
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        className="h-8 rounded-full px-3 text-xs font-bold"
                                                    >
                                                        <BadgeCheck className="mr-1 size-3.5" />
                                                        Verifikasi
                                                    </Button>
                                                )}
                                                {tx.can_reject && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setRejectTxId(
                                                                tx.id,
                                                            );
                                                            setRejectTxReason(
                                                                '',
                                                            );
                                                        }}
                                                        className="h-8 rounded-full px-3 text-xs font-bold text-rose-700"
                                                    >
                                                        <XCircle className="mr-1 size-3.5" />
                                                        Tolak
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                    {invoice.payments.length === 0 && (
                                        <p className="text-ink/45 text-xs">
                                            Belum ada pembayaran tercatat untuk
                                            tagihan ini.
                                        </p>
                                    )}
                                </div>
                            </section>
                        </div>

                        <aside className="space-y-6 lg:col-span-4">
                            <section className="liquid-panel rounded-[26px] p-6 text-xs">
                                <p className="section-kicker text-cyan">
                                    Metadata
                                </p>
                                <h2 className="text-ink mt-1 text-base font-black">
                                    Informasi Tagihan
                                </h2>
                                <dl className="mt-4 space-y-2.5">
                                    <div className="flex justify-between">
                                        <dt className="text-ink/50 font-bold">
                                            Tanggal Terbit
                                        </dt>
                                        <dd className="text-ink font-black">
                                            {invoice.issue_date_label ?? '-'}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-ink/50 font-bold">
                                            Jatuh Tempo
                                        </dt>
                                        <dd className="text-ink font-black">
                                            {invoice.due_date_label ?? '-'}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-ink/50 font-bold">
                                            Diterbitkan Oleh
                                        </dt>
                                        <dd className="text-ink font-bold">
                                            {invoice.issued_by_name ?? '-'}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-ink/50 font-bold">
                                            Lunas Pada
                                        </dt>
                                        <dd className="font-bold text-emerald-700">
                                            {invoice.paid_at ?? '-'}
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            {isInternal && (
                                <section className="liquid-panel rounded-[26px] p-6">
                                    <div className="flex items-center gap-2">
                                        <ShieldCheck className="text-cyan size-4" />
                                        <h2 className="text-ink text-sm font-black">
                                            Audit Trail Tagihan
                                        </h2>
                                    </div>
                                    <div className="mt-4 space-y-3 text-xs">
                                        {auditLogs.map((log) => (
                                            <div
                                                key={log.id}
                                                className="surface-tile rounded-xl p-3"
                                            >
                                                <p className="text-foreground font-black">
                                                    {log.event}
                                                </p>
                                                <p className="text-ink/55 mt-0.5 text-[11px]">
                                                    {log.actor_name} ·{' '}
                                                    {log.created_at}
                                                </p>
                                            </div>
                                        ))}
                                        {auditLogs.length === 0 && (
                                            <p className="text-ink/45">
                                                Belum ada catatan audit.
                                            </p>
                                        )}
                                    </div>
                                </section>
                            )}
                        </aside>
                    </div>
                </div>
            </div>
        </>
    );
}

InvoiceShowPage.layout = {
    breadcrumbs: [
        { title: 'Tagihan & Invoice', href: invoicesIndex() },
        { title: 'Detail Tagihan', href: invoicesIndex() },
    ],
};

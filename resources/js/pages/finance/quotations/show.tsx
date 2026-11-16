import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    FileCheck2,
    FileSpreadsheet,
    Lock,
    Send,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { show as invoiceShow } from '@/routes/finance/invoices';
import {
    approve as quotationApprove,
    convert as quotationConvert,
    index as quotationsIndex,
    reject as quotationReject,
    send as quotationSend,
} from '@/routes/finance/quotations';
import { show as orderShow } from '@/routes/orders';

type QuotationItem = {
    id: number;
    item_type: string;
    label: string;
    quantity: string;
    unit_price: number;
    total: number;
    sku: string | null;
    estimated_unit_cost?: number;
};

type SnapshotItem = {
    id?: number;
    item_type?: string;
    label?: string;
    quantity?: string;
    unit_price?: number;
    total?: number;
};

type QuotationDetail = {
    id: string;
    quotation_number: string;
    service_order_id: string;
    ticket_number: string | null;
    customer_name: string;
    customer_email: string | null;
    customer_phone: string | null;
    version: number;
    status: string;
    labor_total: number;
    spare_parts_total: number;
    operational_total: number;
    subtotal: number;
    discount_amount: number;
    tax_rate: string;
    tax_amount: number;
    total: number;
    notes: string | null;
    valid_until: string | null;
    valid_until_label: string | null;
    sent_at: string | null;
    approved_at: string | null;
    approved_by_name: string | null;
    rejected_at: string | null;
    rejected_by_name: string | null;
    rejection_reason: string | null;
    converted_at: string | null;
    created_by_name: string;
    approved_snapshot: {
        quotation_number?: string;
        approved_at?: string;
        approved_by_name?: string;
        total?: number;
        items?: SnapshotItem[];
    } | null;
    items: QuotationItem[];
    invoice: {
        id: string;
        invoice_number: string;
        status: string;
        total_amount: number;
        paid_amount: number;
    } | null;
};

type AuditEntry = {
    id: number;
    event: string;
    actor_name: string;
    after: Record<string, unknown> | null;
    created_at: string;
};

const money = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const typeLabels: Record<string, string> = {
    labor: 'Jasa / Labor',
    spare_part: 'Spare-Part',
    operational: 'Operasional',
    other: 'Lainnya',
};

const statusMeta: Record<string, { label: string; className: string }> = {
    draft: {
        label: 'Draf',
        className: 'border border-border bg-muted text-muted-foreground',
    },
    sent: {
        label: 'Menunggu Persetujuan Pelanggan',
        className: 'status-pill-warning',
    },
    approved: {
        label: 'Disetujui (Snapshot Terkunci)',
        className: 'status-pill-success',
    },
    rejected: { label: 'Ditolak', className: 'status-pill-danger' },
    expired: {
        label: 'Kedaluwarsa',
        className:
            'border border-orange-500/30 bg-orange-500/12 text-orange-700 dark:text-orange-300',
    },
    converted: {
        label: 'Sudah Dikonversi ke Tagihan',
        className: 'status-pill-info',
    },
};

export default function QuotationShowPage({
    isInternal,
    quotation,
    permissions,
    auditLogs,
}: {
    isInternal: boolean;
    quotation: QuotationDetail;
    permissions: {
        can_update: boolean;
        can_send: boolean;
        can_approve: boolean;
        can_reject: boolean;
        can_convert: boolean;
    };
    auditLogs: AuditEntry[];
}) {
    const [showRejectBox, setShowRejectBox] = useState(false);

    const approveForm = useForm({ notes: '' });
    const rejectForm = useForm({ reason: '' });
    const convertForm = useForm({ issue_now: true });

    const badge = statusMeta[quotation.status] ?? {
        label: quotation.status,
        className: 'border border-border bg-muted text-muted-foreground',
    };

    const snapshotItems = quotation.approved_snapshot?.items ?? null;

    function handleApprove(event: FormEvent) {
        event.preventDefault();
        approveForm.post(quotationApprove.url(quotation.id), {
            preserveScroll: true,
        });
    }

    function handleReject(event: FormEvent) {
        event.preventDefault();
        rejectForm.post(quotationReject.url(quotation.id), {
            preserveScroll: true,
            onSuccess: () => setShowRejectBox(false),
        });
    }

    function handleConvert() {
        convertForm.post(quotationConvert.url(quotation.id));
    }

    return (
        <>
            <Head title={`Penawaran ${quotation.quotation_number}`} />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-6xl space-y-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link
                            href={quotationsIndex.url()}
                            className="border-border bg-card text-foreground hover:bg-muted inline-flex items-center gap-2 rounded-full border px-4 py-2 text-xs font-black shadow-xs transition"
                        >
                            <ArrowLeft className="size-3.5" />
                            Semua Penawaran
                        </Link>
                        {quotation.ticket_number && (
                            <Link
                                href={orderShow.url(quotation.service_order_id)}
                                className="border-border bg-card text-foreground hover:bg-muted inline-flex items-center gap-2 rounded-full border px-4 py-2 text-xs font-black shadow-xs transition"
                            >
                                Tiket {quotation.ticket_number}
                            </Link>
                        )}
                    </div>

                    <header className="liquid-panel-dark rounded-[28px] p-6 text-white sm:p-8">
                        <div className="flex flex-wrap items-start justify-between gap-6">
                            <div>
                                <div className="flex flex-wrap items-center gap-2.5">
                                    <p className="text-cyan text-xs font-black tracking-[.15em] uppercase">
                                        Quotation v{quotation.version}
                                    </p>
                                    <span
                                        className={`rounded-full px-3 py-1 text-[11px] font-black ${badge.className}`}
                                    >
                                        {badge.label}
                                    </span>
                                </div>
                                <h1 className="mt-2 text-3xl font-black tracking-[-.04em]">
                                    {quotation.quotation_number}
                                </h1>
                                <p className="mt-2 text-sm text-white/70">
                                    Pelanggan:{' '}
                                    <strong>{quotation.customer_name}</strong>
                                    {quotation.valid_until_label
                                        ? ` · Berlaku s/d ${quotation.valid_until_label}`
                                        : ''}
                                </p>
                            </div>

                            <div className="text-right">
                                <p className="text-xs font-bold text-white/55 uppercase">
                                    Total Penawaran
                                </p>
                                <p className="mt-1 text-3xl font-black text-white">
                                    {money.format(quotation.total)}
                                </p>
                                <p className="mt-1 text-xs text-white/60">
                                    Subtotal {money.format(quotation.subtotal)}{' '}
                                    · Diskon{' '}
                                    {money.format(quotation.discount_amount)} ·
                                    Pajak {quotation.tax_rate}%
                                </p>
                            </div>
                        </div>

                        <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-white/12 pt-5">
                            {permissions.can_send && (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            quotationSend.url(quotation.id),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="rounded-full bg-white text-xs font-black text-slate-900 hover:bg-white/90"
                                >
                                    <Send className="mr-1.5 size-3.5" />
                                    Kirim ke Pelanggan
                                </Button>
                            )}

                            {permissions.can_approve && (
                                <Button
                                    type="button"
                                    onClick={handleApprove}
                                    disabled={approveForm.processing}
                                    className="rounded-full bg-emerald-500 text-xs font-black text-white hover:bg-emerald-600"
                                >
                                    <CheckCircle2 className="mr-1.5 size-3.5" />
                                    Setujui Penawaran
                                </Button>
                            )}

                            {permissions.can_reject && (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() => setShowRejectBox((v) => !v)}
                                    className="rounded-full text-xs font-black"
                                >
                                    <XCircle className="mr-1.5 size-3.5" />
                                    Tolak Penawaran
                                </Button>
                            )}

                            {permissions.can_convert && (
                                <Button
                                    type="button"
                                    onClick={handleConvert}
                                    disabled={convertForm.processing}
                                    className="rounded-full bg-cyan-400 text-xs font-black text-slate-950 hover:bg-cyan-300"
                                >
                                    <FileCheck2 className="mr-1.5 size-3.5" />
                                    Konversi & Terbitkan Tagihan
                                </Button>
                            )}

                            {quotation.invoice && (
                                <Link
                                    href={invoiceShow.url(quotation.invoice.id)}
                                    className="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-xs font-black text-white hover:bg-white/25"
                                >
                                    <FileSpreadsheet className="size-3.5" />
                                    Lihat Tagihan{' '}
                                    {quotation.invoice.invoice_number} (
                                    {quotation.invoice.status})
                                </Link>
                            )}
                        </div>
                    </header>

                    {showRejectBox && permissions.can_reject && (
                        <form
                            onSubmit={handleReject}
                            className="liquid-panel rounded-[24px] border border-rose-500/30 p-6"
                        >
                            <h2 className="text-sm font-black text-rose-700 dark:text-rose-300">
                                Tolak Penawaran {quotation.quotation_number}
                            </h2>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Jelaskan alasan penolakan atau penyesuaian biaya
                                yang diharapkan.
                            </p>
                            <div className="mt-3 flex flex-wrap gap-3">
                                <Input
                                    value={rejectForm.data.reason}
                                    onChange={(e) =>
                                        rejectForm.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Misal: Harga spare-part terlalu tinggi, mohon opsi alternatif..."
                                    required
                                    className="flex-1"
                                />
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={rejectForm.processing}
                                    className="rounded-full text-xs font-black"
                                >
                                    Kirim Penolakan
                                </Button>
                            </div>
                            <InputError
                                message={rejectForm.errors.reason}
                                className="mt-2"
                            />
                        </form>
                    )}

                    {quotation.status === 'rejected' &&
                        quotation.rejection_reason && (
                            <div className="rounded-[22px] border border-rose-500/25 bg-rose-500/10 p-5 text-xs text-rose-800 dark:text-rose-200">
                                <p className="font-black">
                                    Ditolak pada {quotation.rejected_at} oleh{' '}
                                    {quotation.rejected_by_name}
                                </p>
                                <p className="mt-1">
                                    {quotation.rejection_reason}
                                </p>
                            </div>
                        )}

                    <div className="grid gap-6 lg:grid-cols-12">
                        <section className="liquid-panel rounded-[26px] p-6 lg:col-span-8">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        {snapshotItems !== null
                                            ? 'Immutable approved snapshot'
                                            : 'Line items breakdown'}
                                    </p>
                                    <h2 className="text-ink mt-1 text-lg font-black">
                                        Rincian Item Biaya
                                    </h2>
                                </div>
                                {snapshotItems !== null && (
                                    <span className="status-pill-success inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[11px] font-black">
                                        <Lock className="size-3" />
                                        Snapshot Terkunci (
                                        {quotation.approved_at})
                                    </span>
                                )}
                            </div>

                            <div className="mt-5 overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-border text-muted-foreground border-b text-[11px] font-black uppercase">
                                            <th className="py-2.5 pr-3">
                                                Item
                                            </th>
                                            <th className="py-2.5 pr-3">
                                                Kategori
                                            </th>
                                            <th className="py-2.5 pr-3 text-right">
                                                Qty
                                            </th>
                                            <th className="py-2.5 pr-3 text-right">
                                                Harga Satuan
                                            </th>
                                            {isInternal && (
                                                <th className="py-2.5 pr-3 text-right">
                                                    Est. Modal
                                                </th>
                                            )}
                                            <th className="py-2.5 text-right">
                                                Total
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {quotation.items.map((item) => (
                                            <tr key={item.id}>
                                                <td className="py-3 pr-3">
                                                    <p className="text-foreground font-black">
                                                        {item.label}
                                                    </p>
                                                    {item.sku && (
                                                        <p className="text-muted-foreground text-[10px]">
                                                            SKU: {item.sku}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="text-muted-foreground py-3 pr-3 font-bold">
                                                    {typeLabels[
                                                        item.item_type
                                                    ] ?? item.item_type}
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
                                                            item.estimated_unit_cost ??
                                                                0,
                                                        )}
                                                    </td>
                                                )}
                                                <td className="text-foreground py-3 text-right font-black">
                                                    {money.format(item.total)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="border-border mt-5 space-y-1.5 border-t pt-4 text-xs">
                                <div className="text-muted-foreground flex justify-between">
                                    <span>Biaya Jasa (Labor)</span>
                                    <span className="text-foreground font-bold">
                                        {money.format(quotation.labor_total)}
                                    </span>
                                </div>
                                <div className="text-muted-foreground flex justify-between">
                                    <span>Spare-Part</span>
                                    <span className="text-foreground font-bold">
                                        {money.format(
                                            quotation.spare_parts_total,
                                        )}
                                    </span>
                                </div>
                                <div className="text-muted-foreground flex justify-between">
                                    <span>Biaya Operasional / Lainnya</span>
                                    <span className="text-foreground font-bold">
                                        {money.format(
                                            quotation.operational_total,
                                        )}
                                    </span>
                                </div>
                                <div className="border-border text-foreground flex justify-between border-t pt-2 font-bold">
                                    <span>Subtotal</span>
                                    <span>
                                        {money.format(quotation.subtotal)}
                                    </span>
                                </div>
                                <div className="text-muted-foreground flex justify-between">
                                    <span>Diskon</span>
                                    <span>
                                        -{' '}
                                        {money.format(
                                            quotation.discount_amount,
                                        )}
                                    </span>
                                </div>
                                <div className="text-muted-foreground flex justify-between">
                                    <span>Pajak ({quotation.tax_rate}%)</span>
                                    <span>
                                        + {money.format(quotation.tax_amount)}
                                    </span>
                                </div>
                                <div className="border-border text-foreground flex justify-between border-t pt-2 text-base font-black">
                                    <span>Total Akhir</span>
                                    <span>{money.format(quotation.total)}</span>
                                </div>
                            </div>

                            {quotation.notes && (
                                <div className="surface-tile text-muted-foreground mt-4 rounded-2xl p-4 text-xs">
                                    <p className="text-foreground font-black">
                                        Catatan Penawaran
                                    </p>
                                    <p className="mt-1 whitespace-pre-line">
                                        {quotation.notes}
                                    </p>
                                </div>
                            )}
                        </section>

                        <aside className="space-y-6 lg:col-span-4">
                            <section className="liquid-panel rounded-[26px] p-6 text-xs">
                                <p className="section-kicker text-cyan">
                                    Lifecycle
                                </p>
                                <h2 className="text-ink mt-1 text-base font-black">
                                    Jejak Status
                                </h2>
                                <dl className="mt-4 space-y-2.5">
                                    <div className="flex justify-between">
                                        <dt className="text-ink/50 font-bold">
                                            Dibuat oleh
                                        </dt>
                                        <dd className="text-ink font-black">
                                            {quotation.created_by_name}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-ink/50 font-bold">
                                            Dikirim
                                        </dt>
                                        <dd className="text-ink font-bold">
                                            {quotation.sent_at ?? '-'}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-ink/50 font-bold">
                                            Disetujui
                                        </dt>
                                        <dd className="font-bold text-emerald-700">
                                            {quotation.approved_at
                                                ? `${quotation.approved_at} (${quotation.approved_by_name})`
                                                : '-'}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-ink/50 font-bold">
                                            Dikonversi Invoice
                                        </dt>
                                        <dd className="text-ink font-bold">
                                            {quotation.converted_at ?? '-'}
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            {isInternal && (
                                <section className="liquid-panel rounded-[26px] p-6">
                                    <div className="flex items-center gap-2">
                                        <ShieldCheck className="text-cyan size-4" />
                                        <h2 className="text-ink text-sm font-black">
                                            Audit Trail Penawaran
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

QuotationShowPage.layout = {
    breadcrumbs: [
        { title: 'Penawaran Biaya', href: quotationsIndex() },
        { title: 'Detail Penawaran', href: quotationsIndex() },
    ],
};

import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownLeft,
    ArrowUpRight,
    BadgeCheck,
    Banknote,
    CheckCircle2,
    CircleDollarSign,
    Clock3,
    ExternalLink,
    FileSpreadsheet,
    Landmark,
    ReceiptText,
    Search,
    ShieldCheck,
    TrendingUp,
    XCircle,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    index as financeIndex,
    store as storeTransaction,
} from '@/routes/finance';
import {
    index as invoicesIndex,
    show as invoiceShow,
} from '@/routes/finance/invoices';
import { index as quotationsIndex } from '@/routes/finance/quotations';
import {
    reject as rejectTransaction,
    verify as verifyTransaction,
} from '@/routes/finance/transactions';
import { show as orderShow } from '@/routes/orders';

type Transaction = {
    id: number;
    type: 'income' | 'expense';
    category: string;
    amount: number;
    payment_method: string;
    status: 'pending' | 'verified' | 'rejected' | 'void';
    reference: string | null;
    description: string | null;
    invoice_id: string | null;
    invoice_number: string | null;
    ticket_number: string | null;
    customer_name: string | null;
    actor: string;
    verifier: string | null;
    rejecter: string | null;
    rejection_reason: string | null;
    proof_url: string | null;
    occurred_at: string;
    can_verify: boolean;
    can_reject: boolean;
};

type PaginatedTransactions = {
    data: Transaction[];
    current_page: number;
    last_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type OrderOption = { id: string; ticket_number: string; customer_name: string };
type OpenInvoiceOption = {
    id: string;
    invoice_number: string;
    service_order_id: string | null;
    customer_name: string;
    outstanding_balance: number;
};

type PaymentMethodOption = { value: string; label: string };

type SummaryMetrics = {
    period: { start_date: string | null; end_date: string | null };
    invoiced_revenue: number;
    invoiced_count: number;
    cash_received: number;
    pending_income: number;
    outstanding_receivables: number;
    overdue_receivables: number;
    overdue_invoices_count: number;
    inventory_purchase_expenses: number;
    pending_inventory_expenses: number;
    operational_expenses: number;
    verified_expenses_total: number;
    pending_expenses_total: number;
    spare_part_cogs: number;
    gross_profit_accrual: number;
    net_cash_flow: number;
    quotations: {
        draft: number;
        sent: number;
        approved: number;
        rejected: number;
        expired: number;
        converted: number;
    };
};

type OrderProfitabilityRow = {
    service_order_id: string;
    ticket_number: string;
    customer_name: string;
    status: string;
    estimated_total: number;
    invoiced_total: number;
    cash_received: number;
    outstanding_receivables: number;
    spare_part_cogs: number;
    direct_expenses: number;
    gross_profit: number;
    cash_margin: number;
};

const money = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const statusBadges: Record<string, { label: string; className: string }> = {
    verified: {
        label: 'Terverifikasi',
        className: 'status-pill-success',
    },
    pending: {
        label: 'Menunggu Verifikasi',
        className: 'status-pill-warning',
    },
    rejected: { label: 'Ditolak', className: 'status-pill-danger' },
    void: { label: 'Void', className: 'bg-muted text-muted-foreground' },
};

function makeIdempotencyKey(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return `fin-${crypto.randomUUID()}`;
    }

    return `fin-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

export default function FinancePage({
    role,
    canCreate,
    filters,
    stats,
    summary,
    orderProfitability,
    paymentMethods,
    transactions,
    orders,
    openInvoices,
}: {
    role: string;
    canCreate: boolean;
    filters: {
        start_date: string;
        end_date: string;
        type: string;
        status: string;
        q: string;
    };
    stats: {
        income: number;
        expense: number;
        balance: number;
        pending: number;
    };
    summary: SummaryMetrics;
    orderProfitability: OrderProfitabilityRow[];
    paymentMethods: PaymentMethodOption[];
    transactions: PaginatedTransactions;
    orders: OrderOption[];
    openInvoices: OpenInvoiceOption[];
}) {
    const [activeTab, setActiveTab] = useState<
        'ledger' | 'profitability' | 'breakdown'
    >('ledger');
    const [searchQuery, setSearchQuery] = useState(filters.q);
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);
    const [typeFilter, setTypeFilter] = useState(filters.type);
    const [statusFilter, setStatusFilter] = useState(filters.status);
    const [rejectingId, setRejectingId] = useState<number | null>(null);
    const [rejectReason, setRejectReason] = useState('');

    const form = useForm<{
        service_order_id: string;
        invoice_id: string;
        type: 'income' | 'expense';
        category: string;
        amount: string;
        payment_method: string;
        reference: string;
        idempotency_key: string;
        description: string;
        occurred_at: string;
        proof: File | null;
    }>({
        service_order_id: '',
        invoice_id: '',
        type: 'income',
        category: 'service_payment',
        amount: '',
        payment_method: paymentMethods[0]?.value ?? 'bank_transfer',
        reference: '',
        idempotency_key: makeIdempotencyKey(),
        description: '',
        occurred_at: new Date().toISOString().slice(0, 10),
        proof: null,
    });

    const paymentMethodLabels = Object.fromEntries(
        paymentMethods.map((method) => [method.value, method.label]),
    );

    function applyFilters(overrides?: Partial<typeof filters>) {
        const next = {
            q: overrides?.q ?? searchQuery,
            start_date: overrides?.start_date ?? startDate,
            end_date: overrides?.end_date ?? endDate,
            type: overrides?.type ?? typeFilter,
            status: overrides?.status ?? statusFilter,
        };

        router.get(
            financeIndex.url({
                query: {
                    q: next.q || undefined,
                    start_date: next.start_date || undefined,
                    end_date: next.end_date || undefined,
                    type: next.type !== 'all' ? next.type : undefined,
                    status: next.status !== 'all' ? next.status : undefined,
                },
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(storeTransaction.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset(
                    'amount',
                    'reference',
                    'description',
                    'proof',
                    'invoice_id',
                );
                form.setData('idempotency_key', makeIdempotencyKey());
            },
        });
    }

    function verify(id: number) {
        router.patch(verifyTransaction.url(id), {}, { preserveScroll: true });
    }

    function submitReject(event: FormEvent) {
        event.preventDefault();
        if (rejectingId === null || rejectReason.trim() === '') {
            return;
        }

        router.patch(
            rejectTransaction.url(rejectingId),
            { reason: rejectReason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectingId(null);
                    setRejectReason('');
                },
            },
        );
    }

    const kpiCards = [
        {
            title: 'Pendapatan Ditagihkan (Akrual)',
            value: money.format(summary.invoiced_revenue),
            sub: `${summary.invoiced_count} tagihan resmi terbit`,
            icon: ReceiptText,
            tone: 'status-pill-info',
        },
        {
            title: 'Kas Masuk Terverifikasi',
            value: money.format(summary.cash_received),
            sub:
                summary.pending_income > 0
                    ? `+ ${money.format(summary.pending_income)} menunggu verifikasi`
                    : 'Pembayaran berstatus verified',
            icon: ArrowDownLeft,
            tone: 'status-pill-success',
        },
        {
            title: 'Piutang Berjalan',
            value: money.format(summary.outstanding_receivables),
            sub:
                summary.overdue_invoices_count > 0
                    ? `${summary.overdue_invoices_count} tagihan lewat jatuh tempo (${money.format(summary.overdue_receivables)})`
                    : 'Semua tagihan aktif masih dalam jatuh tempo',
            icon: AlertTriangle,
            tone:
                summary.overdue_invoices_count > 0
                    ? 'status-pill-danger'
                    : 'status-pill-warning',
        },
        {
            title: 'Pengeluaran Ledger & COGS',
            value: money.format(summary.verified_expenses_total),
            sub: `Operasional ${money.format(summary.operational_expenses)} · Stok ${money.format(summary.inventory_purchase_expenses)} · COGS ${money.format(summary.spare_part_cogs)}`,
            icon: ArrowUpRight,
            tone: 'status-pill-danger',
        },
        {
            title: 'Laba Kotor (Akrual)',
            value: money.format(summary.gross_profit_accrual),
            sub: `Arus kas bersih terverifikasi: ${money.format(summary.net_cash_flow)}`,
            icon: TrendingUp,
            tone: 'text-cyan bg-cyan/10',
        },
    ];

    return (
        <>
            <Head title="Keuangan & Akuntabilitas" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <header className="liquid-panel-dark rounded-[28px] p-6 text-white sm:p-8">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p className="text-cyan text-xs font-black tracking-[.15em] uppercase">
                                    Phase 2 · Financial Control & Accuracy
                                </p>
                                <h1 className="mt-2 text-3xl font-black tracking-[-.045em] sm:text-4xl">
                                    Keuangan, Penawaran & Tagihan.
                                </h1>
                                <p className="mt-2 max-w-2xl text-sm leading-6 text-white/62">
                                    Pemisahan tegas antara pendapatan ditagihkan
                                    (akrual), kas masuk terverifikasi, piutang
                                    pelanggan, HPP spare-part (COGS), dan biaya
                                    operasional.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2.5">
                                <Link
                                    href={quotationsIndex.url()}
                                    className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2.5 text-xs font-black text-white backdrop-blur transition hover:bg-white/20"
                                >
                                    <FileSpreadsheet className="size-4" />
                                    Penawaran Biaya
                                </Link>
                                <Link
                                    href={invoicesIndex.url()}
                                    className="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2.5 text-xs font-black text-slate-900 shadow-lg transition hover:bg-white/90"
                                >
                                    <ReceiptText className="size-4" />
                                    Kelola Tagihan
                                </Link>
                            </div>
                        </div>

                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                applyFilters();
                            }}
                            className="mt-6 flex flex-wrap items-end gap-3 border-t border-white/12 pt-5"
                        >
                            <div>
                                <label className="mb-1 block text-[10px] font-bold tracking-wider text-white/55 uppercase">
                                    Dari Tanggal
                                </label>
                                <input
                                    type="date"
                                    value={startDate}
                                    onChange={(e) =>
                                        setStartDate(e.target.value)
                                    }
                                    className="h-9 rounded-xl border border-white/15 bg-white/10 px-3 text-xs font-semibold text-white"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-[10px] font-bold tracking-wider text-white/55 uppercase">
                                    Sampai Tanggal
                                </label>
                                <input
                                    type="date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="h-9 rounded-xl border border-white/15 bg-white/10 px-3 text-xs font-semibold text-white"
                                />
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                variant="secondary"
                                className="h-9 rounded-xl px-4 text-xs font-black"
                            >
                                Terapkan Periode
                            </Button>
                            {(startDate !== '' ||
                                endDate !== '' ||
                                searchQuery !== '' ||
                                typeFilter !== 'all' ||
                                statusFilter !== 'all') && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        setStartDate('');
                                        setEndDate('');
                                        setSearchQuery('');
                                        setTypeFilter('all');
                                        setStatusFilter('all');
                                        router.get(financeIndex.url());
                                    }}
                                    className="h-9 rounded-xl px-3 text-xs font-bold text-white/75 hover:bg-white/10 hover:text-white"
                                >
                                    Reset Filter
                                </Button>
                            )}
                            <div className="ml-auto flex items-center gap-2 rounded-full border border-white/15 bg-white/8 px-3.5 py-1.5 text-xs">
                                <Clock3 className="size-3.5 text-amber-300" />
                                <span className="font-bold text-white/80">
                                    {stats.pending} transaksi menunggu
                                    verifikasi
                                </span>
                            </div>
                        </form>
                    </header>

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        {kpiCards.map((card) => {
                            const Icon = card.icon;
                            return (
                                <div
                                    key={card.title}
                                    className="liquid-panel rounded-[22px] p-5"
                                >
                                    <div
                                        className={`flex size-10 items-center justify-center rounded-2xl ${card.tone}`}
                                    >
                                        <Icon className="size-4" />
                                    </div>
                                    <p className="text-foreground mt-4 text-xl font-black tracking-[-.03em]">
                                        {card.value}
                                    </p>
                                    <p className="text-foreground/85 mt-1 text-xs font-extrabold">
                                        {card.title}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-[11px] leading-4">
                                        {card.sub}
                                    </p>
                                </div>
                            );
                        })}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {[
                            {
                                id: 'ledger',
                                label: 'Ledger & Pencatatan',
                                icon: Banknote,
                            },
                            {
                                id: 'profitability',
                                label: 'Profitabilitas per Order',
                                icon: TrendingUp,
                            },
                            {
                                id: 'breakdown',
                                label: 'Rekonsiliasi & Funnel',
                                icon: CircleDollarSign,
                            },
                        ].map((tab) => {
                            const Icon = tab.icon;
                            const isActive = activeTab === tab.id;
                            return (
                                <button
                                    key={tab.id}
                                    type="button"
                                    onClick={() =>
                                        setActiveTab(tab.id as typeof activeTab)
                                    }
                                    className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-black transition ${
                                        isActive
                                            ? 'bg-primary text-primary-foreground shadow-md'
                                            : 'liquid-control text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    <Icon className="size-3.5" />
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>

                    {activeTab === 'ledger' && (
                        <section
                            className={`grid gap-6 ${canCreate ? 'lg:grid-cols-12' : 'lg:grid-cols-1'}`}
                        >
                            {canCreate && (
                                <form
                                    onSubmit={submit}
                                    className="liquid-panel rounded-[26px] p-6 lg:col-span-5"
                                >
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <p className="section-kicker text-cyan">
                                                Record entry
                                            </p>
                                            <h2 className="text-foreground mt-1 text-lg font-black">
                                                Catat transaksi
                                            </h2>
                                        </div>
                                        <Landmark className="text-cyan size-5" />
                                    </div>
                                    <p className="text-muted-foreground mt-2 text-xs leading-5">
                                        Pembayaran terkait tagihan otomatis
                                        memvalidasi sisa piutang & mencegah
                                        duplikasi/kelebihan bayar.
                                    </p>

                                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                                        <Field
                                            label="Tipe Transaksi"
                                            error={form.errors.type}
                                        >
                                            <select
                                                value={form.data.type}
                                                onChange={(e) => {
                                                    const nextType = e.target
                                                        .value as
                                                        | 'income'
                                                        | 'expense';
                                                    form.setData(
                                                        'type',
                                                        nextType,
                                                    );
                                                    if (
                                                        nextType === 'expense'
                                                    ) {
                                                        form.setData(
                                                            'invoice_id',
                                                            '',
                                                        );
                                                        form.setData(
                                                            'category',
                                                            'operational_expense',
                                                        );
                                                    } else {
                                                        form.setData(
                                                            'category',
                                                            'service_payment',
                                                        );
                                                    }
                                                }}
                                                className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                            >
                                                <option value="income">
                                                    Pemasukan / Pembayaran
                                                </option>
                                                <option value="expense">
                                                    Pengeluaran Operasional
                                                </option>
                                            </select>
                                        </Field>

                                        <Field
                                            label="Kategori"
                                            error={form.errors.category}
                                        >
                                            <Input
                                                value={form.data.category}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'category',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="service_payment / operational"
                                                required
                                            />
                                        </Field>

                                        {form.data.type === 'income' &&
                                            openInvoices.length > 0 && (
                                                <Field
                                                    label="Tautkan ke Tagihan (Opsional)"
                                                    error={
                                                        form.errors.invoice_id
                                                    }
                                                    wide
                                                >
                                                    <select
                                                        value={
                                                            form.data.invoice_id
                                                        }
                                                        onChange={(e) => {
                                                            const invId =
                                                                e.target.value;
                                                            form.setData(
                                                                'invoice_id',
                                                                invId,
                                                            );
                                                            const matched =
                                                                openInvoices.find(
                                                                    (inv) =>
                                                                        inv.id ===
                                                                        invId,
                                                                );
                                                            if (matched) {
                                                                form.setData(
                                                                    'category',
                                                                    'invoice_payment',
                                                                );
                                                                if (
                                                                    matched.service_order_id
                                                                ) {
                                                                    form.setData(
                                                                        'service_order_id',
                                                                        matched.service_order_id,
                                                                    );
                                                                }
                                                                if (
                                                                    !form.data
                                                                        .amount
                                                                ) {
                                                                    form.setData(
                                                                        'amount',
                                                                        String(
                                                                            matched.outstanding_balance,
                                                                        ),
                                                                    );
                                                                }
                                                            }
                                                        }}
                                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                                    >
                                                        <option value="">
                                                            -- Tanpa invoice
                                                            spesifik --
                                                        </option>
                                                        {openInvoices.map(
                                                            (inv) => (
                                                                <option
                                                                    key={inv.id}
                                                                    value={
                                                                        inv.id
                                                                    }
                                                                >
                                                                    {
                                                                        inv.invoice_number
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {
                                                                        inv.customer_name
                                                                    }{' '}
                                                                    (Sisa{' '}
                                                                    {money.format(
                                                                        inv.outstanding_balance,
                                                                    )}
                                                                    )
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </Field>
                                            )}

                                        <Field
                                            label="Tiket Terkait"
                                            error={form.errors.service_order_id}
                                            wide
                                        >
                                            <select
                                                value={
                                                    form.data.service_order_id
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'service_order_id',
                                                        e.target.value,
                                                    )
                                                }
                                                className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                            >
                                                <option value="">
                                                    Umum (non-tiket)
                                                </option>
                                                {orders.map((order) => (
                                                    <option
                                                        key={order.id}
                                                        value={order.id}
                                                    >
                                                        {order.ticket_number} ·{' '}
                                                        {order.customer_name}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>

                                        <Field
                                            label="Nominal (IDR)"
                                            error={form.errors.amount}
                                        >
                                            <Input
                                                type="number"
                                                min={1}
                                                value={form.data.amount}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'amount',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </Field>

                                        <Field
                                            label="Metode Pembayaran"
                                            error={form.errors.payment_method}
                                        >
                                            <select
                                                value={form.data.payment_method}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'payment_method',
                                                        e.target.value,
                                                    )
                                                }
                                                className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                            >
                                                {paymentMethods.map(
                                                    (method) => (
                                                        <option
                                                            key={method.value}
                                                            value={method.value}
                                                        >
                                                            {method.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </Field>

                                        <Field
                                            label="Referensi / No. Bukti"
                                            error={form.errors.reference}
                                        >
                                            <Input
                                                value={form.data.reference}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'reference',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="TRF-2026-001"
                                            />
                                        </Field>

                                        <Field
                                            label="Tanggal Transaksi"
                                            error={form.errors.occurred_at}
                                        >
                                            <Input
                                                type="date"
                                                value={form.data.occurred_at}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'occurred_at',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </Field>

                                        <Field
                                            label="Catatan"
                                            error={form.errors.description}
                                            wide
                                        >
                                            <Input
                                                value={form.data.description}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'description',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Keterangan pembayaran atau pengeluaran..."
                                            />
                                        </Field>

                                        <Field
                                            label="Lampiran Bukti (Opsional, max 5MB)"
                                            error={form.errors.proof}
                                            wide
                                        >
                                            <Input
                                                type="file"
                                                accept="image/*,.pdf"
                                                onChange={(e) =>
                                                    form.setData(
                                                        'proof',
                                                        e.target.files?.[0] ??
                                                            null,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>

                                    <InputError
                                        message={form.errors.idempotency_key}
                                        className="mt-2"
                                    />

                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                        className="mt-5 h-10 w-full rounded-full font-black"
                                    >
                                        {form.processing
                                            ? 'Menyimpan...'
                                            : 'Simpan Entry Ledger'}
                                    </Button>

                                    <div className="border-border text-muted-foreground mt-5 flex items-center gap-2 border-t pt-4 text-xs">
                                        <ShieldCheck className="text-cyan size-4 shrink-0" />
                                        Pemisahan peran aktif ({role}): operator
                                        memverifikasi entry pending.
                                    </div>
                                </form>
                            )}

                            <div
                                className={`liquid-panel rounded-[26px] p-6 ${canCreate ? 'lg:col-span-7' : 'lg:col-span-1'}`}
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Audit-ready ledger
                                        </p>
                                        <h2 className="text-foreground mt-1 text-lg font-black">
                                            Daftar Transaksi (
                                            {transactions.total})
                                        </h2>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <div className="relative">
                                            <Search className="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-3.5" />
                                            <Input
                                                value={searchQuery}
                                                onChange={(e) =>
                                                    setSearchQuery(
                                                        e.target.value,
                                                    )
                                                }
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        applyFilters();
                                                    }
                                                }}
                                                placeholder="Cari ref / tiket / invoice..."
                                                className="h-9 w-48 pl-8 text-xs"
                                            />
                                        </div>
                                        <select
                                            value={typeFilter}
                                            onChange={(e) => {
                                                setTypeFilter(e.target.value);
                                                applyFilters({
                                                    type: e.target.value,
                                                });
                                            }}
                                            className="liquid-input h-9 rounded-xl px-2.5 text-xs font-bold"
                                        >
                                            <option value="all">
                                                Semua Tipe
                                            </option>
                                            <option value="income">
                                                Pemasukan
                                            </option>
                                            <option value="expense">
                                                Pengeluaran
                                            </option>
                                        </select>
                                        <select
                                            value={statusFilter}
                                            onChange={(e) => {
                                                setStatusFilter(e.target.value);
                                                applyFilters({
                                                    status: e.target.value,
                                                });
                                            }}
                                            className="liquid-input h-9 rounded-xl px-2.5 text-xs font-bold"
                                        >
                                            <option value="all">
                                                Semua Status
                                            </option>
                                            <option value="pending">
                                                Pending
                                            </option>
                                            <option value="verified">
                                                Terverifikasi
                                            </option>
                                            <option value="rejected">
                                                Ditolak
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                {rejectingId !== null && (
                                    <form
                                        onSubmit={submitReject}
                                        className="mt-4 rounded-2xl border border-rose-500/25 bg-rose-500/10 p-4"
                                    >
                                        <p className="text-xs font-black text-rose-800 dark:text-rose-200">
                                            Tolak Transaksi #{rejectingId}
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            <Input
                                                value={rejectReason}
                                                onChange={(e) =>
                                                    setRejectReason(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Alasan penolakan (wajib untuk audit trail)..."
                                                required
                                                className="h-9 flex-1 text-xs"
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="destructive"
                                                className="h-9 rounded-xl px-4 text-xs font-black"
                                            >
                                                Konfirmasi Tolak
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    setRejectingId(null);
                                                    setRejectReason('');
                                                }}
                                                className="h-9 text-xs"
                                            >
                                                Batal
                                            </Button>
                                        </div>
                                    </form>
                                )}

                                <div className="mt-5 space-y-3">
                                    {transactions.data.map((tx) => {
                                        const badge = statusBadges[
                                            tx.status
                                        ] ?? {
                                            label: tx.status,
                                            className:
                                                'border border-border bg-muted text-muted-foreground',
                                        };
                                        return (
                                            <div
                                                key={tx.id}
                                                className="liquid-control rounded-[20px] p-4"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-4">
                                                    <div className="space-y-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span
                                                                className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${
                                                                    tx.type ===
                                                                    'income'
                                                                        ? 'status-pill-success'
                                                                        : 'status-pill-danger'
                                                                }`}
                                                            >
                                                                {tx.type ===
                                                                'income'
                                                                    ? 'Masuk'
                                                                    : 'Keluar'}
                                                            </span>
                                                            <span
                                                                className={`rounded-full px-2.5 py-0.5 text-[10px] font-black ${badge.className}`}
                                                            >
                                                                {badge.label}
                                                            </span>
                                                            <span className="text-muted-foreground text-xs font-bold">
                                                                {paymentMethodLabels[
                                                                    tx
                                                                        .payment_method
                                                                ] ??
                                                                    tx.payment_method}
                                                            </span>
                                                            {tx.invoice_id &&
                                                                tx.invoice_number && (
                                                                    <Link
                                                                        href={invoiceShow.url(
                                                                            tx.invoice_id,
                                                                        )}
                                                                        className="text-cyan inline-flex items-center gap-1 text-xs font-black hover:underline"
                                                                    >
                                                                        {
                                                                            tx.invoice_number
                                                                        }
                                                                        <ExternalLink className="size-3" />
                                                                    </Link>
                                                                )}
                                                        </div>
                                                        <p className="text-foreground text-sm font-black">
                                                            {tx.ticket_number
                                                                ? `${tx.ticket_number} · ${tx.customer_name}`
                                                                : tx.category}{' '}
                                                            {tx.reference
                                                                ? `· #${tx.reference}`
                                                                : ''}
                                                        </p>
                                                        {tx.description && (
                                                            <p className="text-muted-foreground text-xs">
                                                                {tx.description}
                                                            </p>
                                                        )}
                                                        <p className="text-muted-foreground/80 text-[11px]">
                                                            {tx.occurred_at} ·
                                                            Dicatat: {tx.actor}
                                                            {tx.verifier
                                                                ? ` · Verifikasi: ${tx.verifier}`
                                                                : ''}
                                                            {tx.rejecter
                                                                ? ` · Ditolak: ${tx.rejecter} (${tx.rejection_reason})`
                                                                : ''}
                                                        </p>
                                                        {tx.proof_url && (
                                                            <a
                                                                href={
                                                                    tx.proof_url
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-cyan inline-flex items-center gap-1 text-xs font-bold hover:underline"
                                                            >
                                                                Lihat Bukti
                                                                Lampiran
                                                                <ExternalLink className="size-3" />
                                                            </a>
                                                        )}
                                                    </div>

                                                    <div className="flex flex-col items-end gap-2">
                                                        <p className="text-ink text-base font-black">
                                                            {tx.type ===
                                                            'expense'
                                                                ? '- '
                                                                : '+ '}
                                                            {money.format(
                                                                tx.amount,
                                                            )}
                                                        </p>
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {tx.can_verify && (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        verify(
                                                                            tx.id,
                                                                        )
                                                                    }
                                                                    className="h-8 rounded-full px-3.5 text-xs font-bold"
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
                                                                        setRejectingId(
                                                                            tx.id,
                                                                        );
                                                                        setRejectReason(
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
                                                </div>
                                            </div>
                                        );
                                    })}

                                    {transactions.data.length === 0 && (
                                        <div className="border-border grid min-h-48 place-items-center rounded-[20px] border border-dashed text-center">
                                            <div>
                                                <ReceiptText className="text-muted-foreground/40 mx-auto size-7" />
                                                <p className="text-foreground mt-3 text-sm font-black">
                                                    Belum ada transaksi
                                                </p>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    Sesuaikan filter tanggal
                                                    atau catat transaksi baru.
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {transactions.last_page > 1 && (
                                    <div className="border-border mt-5 flex items-center justify-between border-t pt-4 text-xs">
                                        <span className="text-muted-foreground font-bold">
                                            Halaman {transactions.current_page}{' '}
                                            dari {transactions.last_page}
                                        </span>
                                        <div className="flex gap-1.5">
                                            {transactions.links.map(
                                                (link, idx) => (
                                                    <button
                                                        key={idx}
                                                        type="button"
                                                        disabled={
                                                            !link.url ||
                                                            link.active
                                                        }
                                                        onClick={() =>
                                                            link.url &&
                                                            router.visit(
                                                                link.url,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        className={`rounded-lg px-2.5 py-1 font-bold ${
                                                            link.active
                                                                ? 'bg-primary text-primary-foreground'
                                                                : 'bg-muted text-muted-foreground hover:text-foreground disabled:opacity-40'
                                                        }`}
                                                        dangerouslySetInnerHTML={{
                                                            __html: link.label,
                                                        }}
                                                    />
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </section>
                    )}

                    {activeTab === 'profitability' && (
                        <section className="liquid-panel rounded-[26px] p-6">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        Order-level cost & revenue
                                    </p>
                                    <h2 className="text-foreground mt-1 text-lg font-black">
                                        Perbandingan Estimasi, Tagihan, Kas
                                        Masuk & COGS per Tiket
                                    </h2>
                                </div>
                            </div>

                            <div className="mt-5 overflow-x-auto">
                                <table className="w-full min-w-[820px] text-left text-xs">
                                    <thead>
                                        <tr className="border-border text-muted-foreground border-b text-[11px] font-black uppercase">
                                            <th className="py-3 pr-3">
                                                Tiket / Pelanggan
                                            </th>
                                            <th className="py-3 pr-3 text-right">
                                                Estimasi Disetujui
                                            </th>
                                            <th className="py-3 pr-3 text-right">
                                                Ditagihkan
                                            </th>
                                            <th className="py-3 pr-3 text-right">
                                                Kas Masuk
                                            </th>
                                            <th className="py-3 pr-3 text-right">
                                                Piutang
                                            </th>
                                            <th className="py-3 pr-3 text-right">
                                                COGS Spare-Part
                                            </th>
                                            <th className="py-3 pr-3 text-right">
                                                Biaya Langsung
                                            </th>
                                            <th className="py-3 text-right">
                                                Laba Kotor (Akrual)
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {orderProfitability.map((row) => (
                                            <tr
                                                key={row.service_order_id}
                                                className="hover:bg-muted/40 transition-colors"
                                            >
                                                <td className="py-3 pr-3">
                                                    <Link
                                                        href={orderShow.url(
                                                            row.service_order_id,
                                                        )}
                                                        className="text-cyan font-black hover:underline"
                                                    >
                                                        {row.ticket_number}
                                                    </Link>
                                                    <p className="text-muted-foreground font-semibold">
                                                        {row.customer_name}
                                                    </p>
                                                </td>
                                                <td className="py-3 pr-3 text-right font-bold">
                                                    {money.format(
                                                        row.estimated_total,
                                                    )}
                                                </td>
                                                <td className="py-3 pr-3 text-right font-bold">
                                                    {money.format(
                                                        row.invoiced_total,
                                                    )}
                                                </td>
                                                <td className="py-3 pr-3 text-right font-bold text-emerald-700">
                                                    {money.format(
                                                        row.cash_received,
                                                    )}
                                                </td>
                                                <td className="py-3 pr-3 text-right font-bold text-amber-700">
                                                    {money.format(
                                                        row.outstanding_receivables,
                                                    )}
                                                </td>
                                                <td className="py-3 pr-3 text-right font-semibold text-rose-700">
                                                    {money.format(
                                                        row.spare_part_cogs,
                                                    )}
                                                </td>
                                                <td className="py-3 pr-3 text-right font-semibold text-rose-700">
                                                    {money.format(
                                                        row.direct_expenses,
                                                    )}
                                                </td>
                                                <td
                                                    className={`py-3 text-right font-black ${
                                                        row.gross_profit >= 0
                                                            ? 'text-emerald-700'
                                                            : 'text-rose-700'
                                                    }`}
                                                >
                                                    {money.format(
                                                        row.gross_profit,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}

                    {activeTab === 'breakdown' && (
                        <section className="grid gap-6 md:grid-cols-2">
                            <div className="liquid-panel rounded-[26px] p-6">
                                <p className="section-kicker text-cyan">
                                    Quotation pipeline
                                </p>
                                <h2 className="text-ink mt-1 text-lg font-black">
                                    Status Penawaran Biaya
                                </h2>
                                <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                    {[
                                        ['Draf', summary.quotations.draft],
                                        ['Terkirim', summary.quotations.sent],
                                        [
                                            'Disetujui',
                                            summary.quotations.approved,
                                        ],
                                        [
                                            'Ditolak',
                                            summary.quotations.rejected,
                                        ],
                                        [
                                            'Kedaluwarsa',
                                            summary.quotations.expired,
                                        ],
                                        [
                                            'Jadi Tagihan',
                                            summary.quotations.converted,
                                        ],
                                    ].map(([label, count]) => (
                                        <div
                                            key={String(label)}
                                            className="liquid-control rounded-2xl p-3.5"
                                        >
                                            <p className="text-muted-foreground text-[11px] font-bold uppercase">
                                                {label}
                                            </p>
                                            <p className="text-foreground mt-1 text-2xl font-black">
                                                {count}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="liquid-panel rounded-[26px] p-6">
                                <p className="section-kicker text-cyan">
                                    Expense vs COGS integrity
                                </p>
                                <h2 className="text-foreground mt-1 text-lg font-black">
                                    Pemisahan Arus Keluar & HPP
                                </h2>
                                <div className="mt-4 space-y-3 text-xs">
                                    <div className="surface-tile flex items-center justify-between rounded-2xl px-4 py-3">
                                        <span className="text-muted-foreground font-bold">
                                            Pembelian Stok Inventaris (INV-MOV)
                                        </span>
                                        <span className="text-foreground font-black">
                                            {money.format(
                                                summary.inventory_purchase_expenses,
                                            )}
                                        </span>
                                    </div>
                                    <div className="surface-tile flex items-center justify-between rounded-2xl px-4 py-3">
                                        <span className="text-muted-foreground font-bold">
                                            Pembelian Stok Pending Verifikasi
                                        </span>
                                        <span className="font-black text-amber-700 dark:text-amber-300">
                                            {money.format(
                                                summary.pending_inventory_expenses,
                                            )}
                                        </span>
                                    </div>
                                    <div className="surface-tile flex items-center justify-between rounded-2xl px-4 py-3">
                                        <span className="text-muted-foreground font-bold">
                                            Pengeluaran Operasional
                                            Terverifikasi
                                        </span>
                                        <span className="text-foreground font-black">
                                            {money.format(
                                                summary.operational_expenses,
                                            )}
                                        </span>
                                    </div>
                                    <div className="surface-tile flex items-center justify-between rounded-2xl px-4 py-3">
                                        <span className="text-muted-foreground font-bold">
                                            HPP Spare-Part Terpasang ke Order
                                            (COGS)
                                        </span>
                                        <span className="font-black text-rose-700 dark:text-rose-300">
                                            {money.format(
                                                summary.spare_part_cogs,
                                            )}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground flex items-center gap-2 pt-2 text-[11px]">
                                        <CheckCircle2 className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                        Pembelian stok dan COGS spare-part
                                        dipisahkan agar tidak terjadi
                                        penghitungan ganda.
                                    </div>
                                </div>
                            </div>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

function Field({
    label,
    error,
    wide = false,
    children,
}: {
    label: string;
    error?: string;
    wide?: boolean;
    children: React.ReactNode;
}) {
    return (
        <label className={wide ? 'sm:col-span-2' : ''}>
            <span className="text-muted-foreground mb-1.5 block text-xs font-bold">
                {label}
            </span>
            {children}
            <InputError message={error} className="mt-1.5" />
        </label>
    );
}

FinancePage.layout = {
    breadcrumbs: [{ title: 'Keuangan', href: financeIndex() }],
};

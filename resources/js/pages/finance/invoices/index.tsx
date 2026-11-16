import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, ReceiptText, Search, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as financeIndex } from '@/routes/finance';
import {
    index as invoicesIndex,
    show as invoiceShow,
    store as invoiceStore,
} from '@/routes/finance/invoices';

type InvoiceRow = {
    id: string;
    invoice_number: string;
    service_order_id: string | null;
    ticket_number: string | null;
    quotation_number: string | null;
    customer_name: string;
    status: string;
    issue_date: string | null;
    due_date: string | null;
    total_amount: number;
    paid_amount: number;
    outstanding_balance: number;
};

type PaginatedInvoices = {
    data: InvoiceRow[];
    current_page: number;
    last_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type OrderOption = {
    id: string;
    ticket_number: string;
    customer_id: number | null;
    customer_name: string;
    email: string | null;
    phone: string | null;
};

type CustomerOption = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
};

type InventoryOption = {
    id: number;
    sku: string;
    name: string;
    unit_cost: number;
    selling_price: number;
};

type LineInput = {
    item_type: 'labor' | 'spare_part' | 'operational' | 'other';
    label: string;
    quantity: string;
    unit_price: string;
    unit_cost: string;
    inventory_item_id: string;
};

const money = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const statusMeta: Record<string, { label: string; className: string }> = {
    draft: {
        label: 'Draf',
        className: 'border border-border bg-muted text-muted-foreground',
    },
    issued: { label: 'Terbit', className: 'status-pill-info' },
    partially_paid: {
        label: 'Dibayar Sebagian',
        className: 'status-pill-warning',
    },
    paid: { label: 'Lunas', className: 'status-pill-success' },
    overdue: { label: 'Jatuh Tempo', className: 'status-pill-danger' },
    cancelled: {
        label: 'Dibatalkan',
        className: 'border border-border bg-muted text-muted-foreground',
    },
};

function defaultItem(): LineInput {
    return {
        item_type: 'labor',
        label: '',
        quantity: '1',
        unit_price: '0',
        unit_cost: '0',
        inventory_item_id: '',
    };
}

export default function InvoicesIndexPage({
    isInternal,
    canCreate,
    filters,
    invoices,
    orders,
    customers,
    inventoryItems,
}: {
    isInternal: boolean;
    canCreate: boolean;
    filters: {
        q: string;
        status: string;
        start_date: string;
        end_date: string;
    };
    invoices: PaginatedInvoices;
    orders: OrderOption[];
    customers: CustomerOption[];
    inventoryItems: InventoryOption[];
}) {
    const [showCreate, setShowCreate] = useState(false);
    const [searchQuery, setSearchQuery] = useState(filters.q);
    const [statusFilter, setStatusFilter] = useState(filters.status);
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);

    const form = useForm<{
        service_order_id: string;
        customer_id: string;
        customer_name: string;
        customer_email: string;
        customer_phone: string;
        discount_amount: string;
        tax_rate: string;
        issue_date: string;
        due_date: string;
        notes: string;
        issue_now: boolean;
        items: LineInput[];
    }>({
        service_order_id: '',
        customer_id: '',
        customer_name: '',
        customer_email: '',
        customer_phone: '',
        discount_amount: '0',
        tax_rate: '0.00',
        issue_date: new Date().toISOString().slice(0, 10),
        due_date: new Date(Date.now() + 7 * 86400000)
            .toISOString()
            .slice(0, 10),
        notes: '',
        issue_now: true,
        items: [defaultItem()],
    });

    function applyFilters(nextStatus?: string) {
        const statusVal = nextStatus ?? statusFilter;
        router.get(
            invoicesIndex.url({
                query: {
                    q: searchQuery.trim() || undefined,
                    status: statusVal !== 'all' ? statusVal : undefined,
                    start_date: startDate || undefined,
                    end_date: endDate || undefined,
                },
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    function updateLine(index: number, patch: Partial<LineInput>) {
        form.setData(
            'items',
            form.data.items.map((row, idx) =>
                idx === index ? { ...row, ...patch } : row,
            ),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            service_order_id: data.service_order_id || null,
            customer_id: data.customer_id
                ? Number.parseInt(data.customer_id, 10)
                : null,
            discount_amount:
                Number.parseInt(data.discount_amount || '0', 10) || 0,
            items: data.items.map((line) => ({
                item_type: line.item_type,
                label: line.label,
                quantity: line.quantity,
                unit_price: Number.parseInt(line.unit_price || '0', 10) || 0,
                unit_cost: Number.parseInt(line.unit_cost || '0', 10) || 0,
                inventory_item_id: line.inventory_item_id
                    ? Number.parseInt(line.inventory_item_id, 10)
                    : null,
            })),
        }));
        form.post(invoiceStore.url(), { preserveScroll: true });
    }

    return (
        <>
            <Head title="Tagihan & Invoice" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <header className="liquid-panel-dark rounded-[28px] p-6 text-white sm:p-8">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p className="text-cyan text-xs font-black tracking-[.15em] uppercase">
                                    Invoicing & Receivables
                                </p>
                                <h1 className="mt-2 text-3xl font-black tracking-[-.045em]">
                                    {isInternal
                                        ? 'Daftar Tagihan & Pembayaran'
                                        : 'Tagihan Saya'}
                                </h1>
                                <p className="mt-2 max-w-2xl text-sm text-white/62">
                                    {isInternal
                                        ? 'Tagihan terkunci saat terbit, mendukung cicilan/pembayaran parsial, deteksi jatuh tempo otomatis, dan pencatatan audit.'
                                        : 'Pantau status tagihan layanan, sisa pembayaran, jatuh tempo, dan riwayat pembayaran terverifikasi.'}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2.5">
                                {isInternal && (
                                    <Link
                                        href={financeIndex.url()}
                                        className="rounded-full border border-white/15 bg-white/10 px-4 py-2.5 text-xs font-black text-white hover:bg-white/20"
                                    >
                                        Kembali ke Keuangan
                                    </Link>
                                )}
                                {canCreate && (
                                    <Button
                                        type="button"
                                        onClick={() =>
                                            setShowCreate((prev) => !prev)
                                        }
                                        className="rounded-full bg-white text-xs font-black text-slate-900 hover:bg-white/90"
                                    >
                                        <Plus className="mr-1.5 size-4" />
                                        {showCreate
                                            ? 'Tutup Form Tagihan'
                                            : 'Buat Tagihan Manual'}
                                    </Button>
                                )}
                            </div>
                        </div>
                    </header>

                    {canCreate && showCreate && (
                        <form
                            onSubmit={submit}
                            className="liquid-panel rounded-[26px] p-6"
                        >
                            <p className="section-kicker text-cyan">
                                Authorized manual invoicing
                            </p>
                            <h2 className="text-ink mt-1 text-lg font-black">
                                Buat Tagihan Manual
                            </h2>

                            <div className="mt-5 grid gap-4 sm:grid-cols-4">
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Tiket Order (Opsional)
                                    </label>
                                    <select
                                        value={form.data.service_order_id}
                                        onChange={(e) => {
                                            const orderId = e.target.value;
                                            const order = orders.find(
                                                (o) => o.id === orderId,
                                            );
                                            form.setData((prev) => ({
                                                ...prev,
                                                service_order_id: orderId,
                                                customer_id: order?.customer_id
                                                    ? String(order.customer_id)
                                                    : prev.customer_id,
                                                customer_name: order
                                                    ? order.customer_name
                                                    : prev.customer_name,
                                                customer_email:
                                                    order?.email ??
                                                    prev.customer_email,
                                                customer_phone:
                                                    order?.phone ??
                                                    prev.customer_phone,
                                            }));
                                        }}
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-xs"
                                    >
                                        <option value="">
                                            -- Standalone / Non-Tiket --
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
                                </div>

                                <div>
                                    <label className="text-muted-foreground mb-1.5 block text-xs font-bold">
                                        Akun Pelanggan (Opsional)
                                    </label>
                                    <select
                                        value={form.data.customer_id}
                                        onChange={(e) => {
                                            const cid = e.target.value;
                                            const customer = customers.find(
                                                (c) => String(c.id) === cid,
                                            );
                                            form.setData((prev) => ({
                                                ...prev,
                                                customer_id: cid,
                                                customer_name: customer
                                                    ? customer.name
                                                    : prev.customer_name,
                                                customer_email:
                                                    customer?.email ??
                                                    prev.customer_email,
                                                customer_phone:
                                                    customer?.phone ??
                                                    prev.customer_phone,
                                            }));
                                        }}
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-xs"
                                    >
                                        <option value="">
                                            -- Pilih akun pelanggan --
                                        </option>
                                        {customers.map((cust) => (
                                            <option
                                                key={cust.id}
                                                value={cust.id}
                                            >
                                                {cust.name} (
                                                {cust.email ??
                                                    cust.phone ??
                                                    '-'}
                                                )
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Nama Pelanggan *
                                    </label>
                                    <Input
                                        value={form.data.customer_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'customer_name',
                                                e.target.value,
                                            )
                                        }
                                        required={!form.data.service_order_id}
                                    />
                                    <InputError
                                        message={form.errors.customer_name}
                                        className="mt-1"
                                    />
                                </div>

                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Jatuh Tempo
                                    </label>
                                    <Input
                                        type="date"
                                        value={form.data.due_date}
                                        onChange={(e) =>
                                            form.setData(
                                                'due_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div className="mt-5 space-y-3">
                                <div className="flex items-center justify-between">
                                    <p className="text-ink text-xs font-black uppercase">
                                        Baris Tagihan
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            form.setData('items', [
                                                ...form.data.items,
                                                defaultItem(),
                                            ])
                                        }
                                        className="h-8 rounded-full text-xs font-bold"
                                    >
                                        <Plus className="mr-1 size-3.5" />
                                        Tambah Item
                                    </Button>
                                </div>

                                {form.data.items.map((line, idx) => (
                                    <div
                                        key={idx}
                                        className="surface-tile grid gap-3 rounded-2xl p-3.5 sm:grid-cols-12"
                                    >
                                        <div className="sm:col-span-2">
                                            <label className="text-ink/50 mb-1 block text-[10px] font-bold uppercase">
                                                Tipe
                                            </label>
                                            <select
                                                value={line.item_type}
                                                onChange={(e) =>
                                                    updateLine(idx, {
                                                        item_type: e.target
                                                            .value as LineInput['item_type'],
                                                    })
                                                }
                                                className="h-9 w-full rounded-xl border border-black/10 bg-white px-2 text-xs font-bold"
                                            >
                                                <option value="labor">
                                                    Jasa
                                                </option>
                                                <option value="spare_part">
                                                    Spare-Part
                                                </option>
                                                <option value="operational">
                                                    Operasional
                                                </option>
                                                <option value="other">
                                                    Lainnya
                                                </option>
                                            </select>
                                        </div>

                                        <div className="sm:col-span-5">
                                            <label className="text-ink/50 mb-1 block text-[10px] font-bold uppercase">
                                                Nama Item
                                            </label>
                                            {line.item_type === 'spare_part' &&
                                                inventoryItems.length > 0 && (
                                                    <select
                                                        value={
                                                            line.inventory_item_id
                                                        }
                                                        onChange={(e) => {
                                                            const inv =
                                                                inventoryItems.find(
                                                                    (i) =>
                                                                        String(
                                                                            i.id,
                                                                        ) ===
                                                                        e.target
                                                                            .value,
                                                                );
                                                            if (inv) {
                                                                updateLine(
                                                                    idx,
                                                                    {
                                                                        inventory_item_id:
                                                                            String(
                                                                                inv.id,
                                                                            ),
                                                                        label: inv.name,
                                                                        unit_price:
                                                                            String(
                                                                                inv.selling_price,
                                                                            ),
                                                                        unit_cost:
                                                                            String(
                                                                                inv.unit_cost,
                                                                            ),
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                        className="mb-1.5 h-8 w-full rounded-lg border border-black/10 bg-white px-2 text-[11px]"
                                                    >
                                                        <option value="">
                                                            -- Katalog Gudang
                                                            (Opsional) --
                                                        </option>
                                                        {inventoryItems.map(
                                                            (inv) => (
                                                                <option
                                                                    key={inv.id}
                                                                    value={
                                                                        inv.id
                                                                    }
                                                                >
                                                                    {inv.sku} ·{' '}
                                                                    {inv.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                )}
                                            <Input
                                                value={line.label}
                                                onChange={(e) =>
                                                    updateLine(idx, {
                                                        label: e.target.value,
                                                    })
                                                }
                                                required
                                                className="h-9 text-xs"
                                            />
                                        </div>

                                        <div className="sm:col-span-2">
                                            <label className="text-ink/50 mb-1 block text-[10px] font-bold uppercase">
                                                Qty
                                            </label>
                                            <Input
                                                value={line.quantity}
                                                onChange={(e) =>
                                                    updateLine(idx, {
                                                        quantity:
                                                            e.target.value,
                                                    })
                                                }
                                                required
                                                className="h-9 text-xs"
                                            />
                                        </div>

                                        <div className="flex items-end gap-2 sm:col-span-3">
                                            <div className="flex-1">
                                                <label className="text-ink/50 mb-1 block text-[10px] font-bold uppercase">
                                                    Harga Satuan
                                                </label>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    value={line.unit_price}
                                                    onChange={(e) =>
                                                        updateLine(idx, {
                                                            unit_price:
                                                                e.target.value,
                                                        })
                                                    }
                                                    required
                                                    className="h-9 text-xs"
                                                />
                                            </div>
                                            {form.data.items.length > 1 && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        form.setData(
                                                            'items',
                                                            form.data.items.filter(
                                                                (_, i) =>
                                                                    i !== idx,
                                                            ),
                                                        )
                                                    }
                                                    className="mb-1 rounded-lg p-2 text-rose-600 hover:bg-rose-500/12 dark:text-rose-400"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="mt-5 grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Diskon (IDR)
                                    </label>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={form.data.discount_amount}
                                        onChange={(e) =>
                                            form.setData(
                                                'discount_amount',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Pajak (%)
                                    </label>
                                    <Input
                                        value={form.data.tax_rate}
                                        onChange={(e) =>
                                            form.setData(
                                                'tax_rate',
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
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Instruksi transfer..."
                                    />
                                </div>
                            </div>

                            <div className="border-border mt-5 flex flex-wrap items-center justify-between gap-4 border-t pt-4">
                                <label className="text-foreground flex items-center gap-2 text-xs font-bold">
                                    <input
                                        type="checkbox"
                                        checked={form.data.issue_now}
                                        onChange={(e) =>
                                            form.setData(
                                                'issue_now',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    Langsung terbitkan (kunci snapshot tagihan)
                                </label>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-full px-6 text-xs font-black"
                                >
                                    {form.processing
                                        ? 'Memproses...'
                                        : 'Buat Tagihan'}
                                </Button>
                            </div>
                        </form>
                    )}

                    <section className="liquid-panel rounded-[26px] p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-ink text-lg font-black">
                                Daftar Tagihan ({invoices.total})
                            </h2>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="relative">
                                    <Search className="text-ink/35 pointer-events-none absolute top-2.5 left-3 size-3.5" />
                                    <Input
                                        value={searchQuery}
                                        onChange={(e) =>
                                            setSearchQuery(e.target.value)
                                        }
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                applyFilters();
                                            }
                                        }}
                                        placeholder="Cari invoice / pelanggan / tiket..."
                                        className="h-9 w-52 pl-8 text-xs"
                                    />
                                </div>
                                <Input
                                    type="date"
                                    value={startDate}
                                    onChange={(e) =>
                                        setStartDate(e.target.value)
                                    }
                                    className="h-9 w-36 text-xs"
                                />
                                <Input
                                    type="date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="h-9 w-36 text-xs"
                                />
                                <select
                                    value={statusFilter}
                                    onChange={(e) => {
                                        setStatusFilter(e.target.value);
                                        applyFilters(e.target.value);
                                    }}
                                    className="liquid-input h-9 rounded-xl px-3 text-xs font-bold"
                                >
                                    <option value="all">Semua Status</option>
                                    {isInternal && (
                                        <option value="draft">Draf</option>
                                    )}
                                    <option value="issued">Terbit</option>
                                    <option value="partially_paid">
                                        Dibayar Sebagian
                                    </option>
                                    <option value="paid">Lunas</option>
                                    <option value="overdue">Jatuh Tempo</option>
                                    <option value="cancelled">
                                        Dibatalkan
                                    </option>
                                </select>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => applyFilters()}
                                    className="h-9 rounded-xl text-xs font-black"
                                >
                                    Filter
                                </Button>
                            </div>
                        </div>

                        <div className="mt-5 space-y-3">
                            {invoices.data.map((inv) => {
                                const badge = statusMeta[inv.status] ?? {
                                    label: inv.status,
                                    className:
                                        'border border-border bg-muted text-muted-foreground',
                                };
                                return (
                                    <div
                                        key={inv.id}
                                        className="surface-tile flex flex-wrap items-center justify-between gap-4 rounded-[20px] p-4"
                                    >
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    href={invoiceShow.url(
                                                        inv.id,
                                                    )}
                                                    className="text-foreground text-sm font-black hover:underline"
                                                >
                                                    {inv.invoice_number}
                                                </Link>
                                                <span
                                                    className={`rounded-full px-2.5 py-0.5 text-[10px] font-black ${badge.className}`}
                                                >
                                                    {badge.label}
                                                </span>
                                                {inv.quotation_number && (
                                                    <span className="border-border bg-muted text-muted-foreground rounded-full border px-2.5 py-0.5 text-[10px] font-bold">
                                                        Dari{' '}
                                                        {inv.quotation_number}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="text-ink/75 mt-1 text-xs font-semibold">
                                                {inv.customer_name}{' '}
                                                {inv.ticket_number
                                                    ? `· ${inv.ticket_number}`
                                                    : ''}
                                            </p>
                                            <p className="text-ink/45 mt-0.5 text-[11px]">
                                                Terbit: {inv.issue_date ?? '-'}{' '}
                                                · Jatuh Tempo:{' '}
                                                {inv.due_date ?? '-'}
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-4">
                                            <div className="text-right text-xs">
                                                <p className="text-ink text-base font-black">
                                                    {money.format(
                                                        inv.total_amount,
                                                    )}
                                                </p>
                                                <p className="font-bold text-emerald-700">
                                                    Terbayar:{' '}
                                                    {money.format(
                                                        inv.paid_amount,
                                                    )}
                                                </p>
                                                {inv.outstanding_balance >
                                                    0 && (
                                                    <p className="font-bold text-amber-600 dark:text-amber-400">
                                                        Sisa:{' '}
                                                        {money.format(
                                                            inv.outstanding_balance,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                            <Link
                                                href={invoiceShow.url(inv.id)}
                                                className="bg-primary text-primary-foreground rounded-full px-4 py-2 text-xs font-black transition hover:opacity-90"
                                            >
                                                Detail
                                            </Link>
                                        </div>
                                    </div>
                                );
                            })}

                            {invoices.data.length === 0 && (
                                <div className="border-border grid min-h-48 place-items-center rounded-[20px] border border-dashed text-center">
                                    <div>
                                        <ReceiptText className="text-muted-foreground/30 mx-auto size-7" />
                                        <p className="text-foreground mt-3 text-sm font-black">
                                            Belum ada tagihan
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>

                        {invoices.last_page > 1 && (
                            <div className="border-border mt-5 flex items-center justify-between border-t pt-4 text-xs">
                                <span className="text-muted-foreground font-bold">
                                    Halaman {invoices.current_page} dari{' '}
                                    {invoices.last_page}
                                </span>
                                <div className="flex gap-1.5">
                                    {invoices.links.map((link, idx) => (
                                        <button
                                            key={idx}
                                            type="button"
                                            disabled={!link.url || link.active}
                                            onClick={() =>
                                                link.url &&
                                                router.visit(link.url, {
                                                    preserveScroll: true,
                                                })
                                            }
                                            className={`rounded-lg px-2.5 py-1 font-bold transition ${
                                                link.active
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'border-border bg-card text-foreground hover:bg-muted border disabled:opacity-40'
                                            }`}
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

InvoicesIndexPage.layout = {
    breadcrumbs: [
        { title: 'Keuangan', href: financeIndex() },
        { title: 'Tagihan & Invoice', href: invoicesIndex() },
    ],
};

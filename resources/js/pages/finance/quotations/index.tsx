import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Search, Send, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as financeIndex } from '@/routes/finance';
import { show as invoiceShow } from '@/routes/finance/invoices';
import {
    index as quotationsIndex,
    show as quotationShow,
    store as quotationStore,
} from '@/routes/finance/quotations';

type QuotationRow = {
    id: string;
    quotation_number: string;
    service_order_id: string;
    ticket_number: string | null;
    customer_name: string;
    version: number;
    status: string;
    subtotal: number;
    discount_amount: number;
    tax_rate: string;
    tax_amount: number;
    total: number;
    valid_until: string | null;
    valid_until_label: string | null;
    sent_at: string | null;
    approved_at: string | null;
    rejected_at: string | null;
    invoice: { id: string; invoice_number: string; status: string } | null;
    can_approve: boolean;
    can_convert: boolean;
};

type PaginatedQuotations = {
    data: QuotationRow[];
    current_page: number;
    last_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type OrderOption = {
    id: string;
    ticket_number: string;
    customer_name: string;
    status: string;
    spare_parts: Array<{
        id: number;
        inventory_item_id: number;
        label: string;
        quantity: number;
        unit_price: number;
        unit_cost: number;
    }>;
};

type InventoryOption = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    unit_cost: number;
    selling_price: number;
};

type DraftLineItem = {
    item_type: 'labor' | 'spare_part' | 'operational' | 'other';
    label: string;
    quantity: string;
    unit_price: string;
    estimated_unit_cost: string;
    inventory_item_id: string;
    service_order_spare_part_id: string;
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
    sent: {
        label: 'Menunggu Persetujuan',
        className: 'status-pill-warning',
    },
    approved: {
        label: 'Disetujui',
        className: 'status-pill-success',
    },
    rejected: { label: 'Ditolak', className: 'status-pill-danger' },
    expired: {
        label: 'Kedaluwarsa',
        className:
            'border border-orange-500/30 bg-orange-500/12 text-orange-700 dark:text-orange-300',
    },
    converted: {
        label: 'Dikonversi ke Invoice',
        className: 'status-pill-info',
    },
};

function defaultLine(): DraftLineItem {
    return {
        item_type: 'labor',
        label: '',
        quantity: '1',
        unit_price: '0',
        estimated_unit_cost: '0',
        inventory_item_id: '',
        service_order_spare_part_id: '',
    };
}

export default function QuotationsIndexPage({
    isInternal,
    canCreate,
    filters,
    defaults,
    quotations,
    orders,
    inventoryItems,
}: {
    isInternal: boolean;
    canCreate: boolean;
    filters: { q: string; status: string };
    defaults: { default_tax_rate: string; quotation_validity_days: number };
    quotations: PaginatedQuotations;
    orders: OrderOption[];
    inventoryItems: InventoryOption[];
}) {
    const [showBuilder, setShowBuilder] = useState(false);
    const [searchQuery, setSearchQuery] = useState(filters.q);
    const [statusFilter, setStatusFilter] = useState(filters.status);

    const defaultValidUntil = new Date(
        Date.now() + defaults.quotation_validity_days * 86400000,
    )
        .toISOString()
        .slice(0, 10);

    const form = useForm<{
        service_order_id: string;
        discount_amount: string;
        tax_rate: string;
        valid_until: string;
        notes: string;
        send_now: boolean;
        items: DraftLineItem[];
    }>({
        service_order_id: orders[0]?.id ?? '',
        discount_amount: '0',
        tax_rate: defaults.default_tax_rate,
        valid_until: defaultValidUntil,
        notes: '',
        send_now: true,
        items: [defaultLine()],
    });

    function applyFilters(nextStatus?: string) {
        const statusVal = nextStatus ?? statusFilter;
        router.get(
            quotationsIndex.url({
                query: {
                    q: searchQuery.trim() || undefined,
                    status: statusVal !== 'all' ? statusVal : undefined,
                },
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    function updateLine(index: number, patch: Partial<DraftLineItem>) {
        const next = form.data.items.map((item, idx) =>
            idx === index ? { ...item, ...patch } : item,
        );
        form.setData('items', next);
    }

    function importOrderSpareParts() {
        const order = orders.find((o) => o.id === form.data.service_order_id);
        if (!order || order.spare_parts.length === 0) {
            return;
        }

        const existingPartIds = new Set(
            form.data.items
                .map((item) => item.service_order_spare_part_id)
                .filter(Boolean),
        );
        const imported: DraftLineItem[] = order.spare_parts
            .filter((part) => !existingPartIds.has(String(part.id)))
            .map((part) => ({
                item_type: 'spare_part',
                label: part.label,
                quantity: String(part.quantity),
                unit_price: String(part.unit_price),
                estimated_unit_cost: String(part.unit_cost),
                inventory_item_id: String(part.inventory_item_id),
                service_order_spare_part_id: String(part.id),
            }));

        if (imported.length > 0) {
            const cleanExisting = form.data.items.filter(
                (i) => i.label.trim() !== '' || Number(i.unit_price) > 0,
            );
            form.setData('items', [...cleanExisting, ...imported]);
        }
    }

    const previewSubtotal = form.data.items.reduce((sum, item) => {
        const qty = Number.parseFloat(item.quantity.replace(',', '.')) || 0;
        const price = Number.parseInt(item.unit_price, 10) || 0;
        return sum + Math.round(qty * price);
    }, 0);
    const previewDiscount = Math.min(
        previewSubtotal,
        Math.max(0, Number.parseInt(form.data.discount_amount || '0', 10) || 0),
    );
    const previewTaxRate =
        Number.parseFloat((form.data.tax_rate || '0').replace(',', '.')) || 0;
    const previewTax = Math.round(
        ((previewSubtotal - previewDiscount) * previewTaxRate) / 100,
    );
    const previewTotal = Math.max(
        0,
        previewSubtotal - previewDiscount + previewTax,
    );

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            discount_amount:
                Number.parseInt(data.discount_amount || '0', 10) || 0,
            items: data.items.map((line) => ({
                item_type: line.item_type,
                label: line.label,
                quantity: line.quantity,
                unit_price: Number.parseInt(line.unit_price || '0', 10) || 0,
                estimated_unit_cost:
                    Number.parseInt(line.estimated_unit_cost || '0', 10) || 0,
                inventory_item_id: line.inventory_item_id
                    ? Number.parseInt(line.inventory_item_id, 10)
                    : null,
                service_order_spare_part_id: line.service_order_spare_part_id
                    ? Number.parseInt(line.service_order_spare_part_id, 10)
                    : null,
            })),
        }));
        form.post(quotationStore.url(), {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Penawaran Biaya & Estimasi" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <header className="liquid-panel-dark rounded-[28px] p-6 text-white sm:p-8">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p className="text-cyan text-xs font-black tracking-[.15em] uppercase">
                                    Quotations & Service Estimates
                                </p>
                                <h1 className="mt-2 text-3xl font-black tracking-[-.045em]">
                                    {isInternal
                                        ? 'Penawaran Biaya Pelanggan'
                                        : 'Penawaran Biaya Saya'}
                                </h1>
                                <p className="mt-2 max-w-2xl text-sm text-white/62">
                                    {isInternal
                                        ? 'Susun rincian biaya jasa, spare-part, dan operasional lengkap dengan masa berlaku & snapshot persetujuan.'
                                        : 'Tinjau rincian biaya layanan yang dikirimkan tim AD Com, setujui atau berikan catatan penolakan.'}
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
                                            setShowBuilder((prev) => !prev)
                                        }
                                        className="rounded-full bg-white text-xs font-black text-slate-900 hover:bg-white/90"
                                    >
                                        <Plus className="mr-1.5 size-4" />
                                        {showBuilder
                                            ? 'Tutup Form Penawaran'
                                            : 'Buat Penawaran Baru'}
                                    </Button>
                                )}
                            </div>
                        </div>
                    </header>

                    {canCreate && showBuilder && (
                        <form
                            onSubmit={submit}
                            className="liquid-panel rounded-[26px] p-6"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        Quotation builder
                                    </p>
                                    <h2 className="text-ink mt-1 text-lg font-black">
                                        Susun Penawaran Baru
                                    </h2>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={importOrderSpareParts}
                                    className="rounded-full text-xs font-bold"
                                >
                                    Tarik Spare-Part dari Tiket
                                </Button>
                            </div>

                            <div className="mt-5 grid gap-4 sm:grid-cols-4">
                                <div className="sm:col-span-2">
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Order Layanan
                                    </label>
                                    <select
                                        value={form.data.service_order_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'service_order_id',
                                                e.target.value,
                                            )
                                        }
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                        required
                                    >
                                        {orders.map((order) => (
                                            <option
                                                key={order.id}
                                                value={order.id}
                                            >
                                                {order.ticket_number} ·{' '}
                                                {order.customer_name} (
                                                {order.status})
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={form.errors.service_order_id}
                                        className="mt-1"
                                    />
                                </div>

                                <div>
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Berlaku Hingga
                                    </label>
                                    <Input
                                        type="date"
                                        value={form.data.valid_until}
                                        onChange={(e) =>
                                            form.setData(
                                                'valid_until',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.valid_until}
                                        className="mt-1"
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
                                        placeholder="11.00"
                                    />
                                    <InputError
                                        message={form.errors.tax_rate}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div className="mt-5 space-y-3">
                                <div className="flex items-center justify-between">
                                    <p className="text-ink text-xs font-black uppercase">
                                        Rincian Item Biaya
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            form.setData('items', [
                                                ...form.data.items,
                                                defaultLine(),
                                            ])
                                        }
                                        className="h-8 rounded-full text-xs font-bold"
                                    >
                                        <Plus className="mr-1 size-3.5" />
                                        Tambah Baris
                                    </Button>
                                </div>

                                {form.data.items.map((line, idx) => (
                                    <div
                                        key={idx}
                                        className="surface-tile grid gap-3 rounded-2xl p-3.5 sm:grid-cols-12"
                                    >
                                        <div className="sm:col-span-2">
                                            <label className="text-ink/50 mb-1 block text-[10px] font-bold uppercase">
                                                Kategori
                                            </label>
                                            <select
                                                value={line.item_type}
                                                onChange={(e) =>
                                                    updateLine(idx, {
                                                        item_type: e.target
                                                            .value as DraftLineItem['item_type'],
                                                    })
                                                }
                                                className="h-9 w-full rounded-xl border border-black/10 bg-white px-2 text-xs font-bold"
                                            >
                                                <option value="labor">
                                                    Jasa / Labor
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

                                        <div className="sm:col-span-4">
                                            <label className="text-ink/50 mb-1 block text-[10px] font-bold uppercase">
                                                Deskripsi Item
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
                                                                        estimated_unit_cost:
                                                                            String(
                                                                                inv.unit_cost,
                                                                            ),
                                                                    },
                                                                );
                                                            } else {
                                                                updateLine(
                                                                    idx,
                                                                    {
                                                                        inventory_item_id:
                                                                            '',
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                        className="mb-1.5 h-8 w-full rounded-lg border border-black/10 bg-white px-2 text-[11px]"
                                                    >
                                                        <option value="">
                                                            -- Pilih dari
                                                            katalog gudang
                                                            (opsional) --
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
                                                                    {inv.name} (
                                                                    {money.format(
                                                                        inv.selling_price,
                                                                    )}
                                                                    )
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
                                                placeholder="Nama jasa / spare-part / biaya..."
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
                                                className="h-9 text-xs"
                                                required
                                            />
                                        </div>

                                        <div className="sm:col-span-2">
                                            <label className="text-ink/50 mb-1 block text-[10px] font-bold uppercase">
                                                Harga Jual (IDR)
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
                                                className="h-9 text-xs"
                                                required
                                            />
                                        </div>

                                        <div className="flex items-end justify-between gap-2 sm:col-span-2">
                                            <div className="flex-1">
                                                <label className="text-ink/50 mb-1 block text-[10px] font-bold uppercase">
                                                    Est. Modal
                                                </label>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    value={
                                                        line.estimated_unit_cost
                                                    }
                                                    onChange={(e) =>
                                                        updateLine(idx, {
                                                            estimated_unit_cost:
                                                                e.target.value,
                                                        })
                                                    }
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
                                                    aria-label="Hapus baris"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                <InputError message={form.errors.items} />
                            </div>

                            <div className="mt-5 grid gap-4 sm:grid-cols-12">
                                <div className="sm:col-span-4">
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
                                <div className="sm:col-span-8">
                                    <label className="text-ink/60 mb-1.5 block text-xs font-bold">
                                        Catatan Penawaran
                                    </label>
                                    <Input
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Syarat pengerjaan, estimasi durasi, garansi part..."
                                    />
                                </div>
                            </div>

                            <div className="border-border mt-5 flex flex-wrap items-center justify-between gap-4 border-t pt-4">
                                <div className="text-muted-foreground text-xs">
                                    <p>
                                        Pratinjau Subtotal:{' '}
                                        <strong>
                                            {money.format(previewSubtotal)}
                                        </strong>{' '}
                                        · Diskon:{' '}
                                        <strong>
                                            {money.format(previewDiscount)}
                                        </strong>{' '}
                                        · Pajak:{' '}
                                        <strong>
                                            {money.format(previewTax)}
                                        </strong>
                                    </p>
                                    <p className="text-foreground mt-1 text-base font-black">
                                        Estimasi Total Server-Recalculated:{' '}
                                        {money.format(previewTotal)}
                                    </p>
                                </div>

                                <div className="flex flex-wrap items-center gap-3">
                                    <label className="text-foreground flex items-center gap-2 text-xs font-bold">
                                        <input
                                            type="checkbox"
                                            checked={form.data.send_now}
                                            onChange={(e) =>
                                                form.setData(
                                                    'send_now',
                                                    e.target.checked,
                                                )
                                            }
                                            className="border-border rounded"
                                        />
                                        Langsung kirim ke pelanggan
                                    </label>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                        className="rounded-full px-6 text-xs font-black"
                                    >
                                        <Send className="mr-1.5 size-3.5" />
                                        {form.processing
                                            ? 'Menyimpan...'
                                            : 'Simpan Penawaran'}
                                    </Button>
                                </div>
                            </div>
                        </form>
                    )}

                    <section className="liquid-panel rounded-[26px] p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-ink text-lg font-black">
                                Daftar Penawaran ({quotations.total})
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
                                        placeholder="Cari nomor penawaran / tiket..."
                                        className="h-9 w-56 pl-8 text-xs"
                                    />
                                </div>
                                <select
                                    value={statusFilter}
                                    onChange={(e) => {
                                        setStatusFilter(e.target.value);
                                        applyFilters(e.target.value);
                                    }}
                                    className="liquid-input h-9 rounded-xl px-3 text-xs font-bold"
                                >
                                    <option value="all">Semua Status</option>
                                    <option value="draft">Draf</option>
                                    <option value="sent">
                                        Menunggu Persetujuan
                                    </option>
                                    <option value="approved">Disetujui</option>
                                    <option value="rejected">Ditolak</option>
                                    <option value="expired">Kedaluwarsa</option>
                                    <option value="converted">
                                        Jadi Tagihan
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div className="mt-5 space-y-3">
                            {quotations.data.map((quo) => {
                                const badge = statusMeta[quo.status] ?? {
                                    label: quo.status,
                                    className:
                                        'border border-border bg-muted text-muted-foreground',
                                };
                                return (
                                    <div
                                        key={quo.id}
                                        className="surface-tile flex flex-wrap items-center justify-between gap-4 rounded-[20px] p-4"
                                    >
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    href={quotationShow.url(
                                                        quo.id,
                                                    )}
                                                    className="text-foreground text-sm font-black hover:underline"
                                                >
                                                    {quo.quotation_number}
                                                </Link>
                                                <span className="border-border bg-muted text-muted-foreground rounded-full border px-2 py-0.5 text-[10px] font-black">
                                                    v{quo.version}
                                                </span>
                                                <span
                                                    className={`rounded-full px-2.5 py-0.5 text-[10px] font-black ${badge.className}`}
                                                >
                                                    {badge.label}
                                                </span>
                                                {quo.invoice && (
                                                    <Link
                                                        href={invoiceShow.url(
                                                            quo.invoice.id,
                                                        )}
                                                        className="text-cyan text-xs font-black hover:underline"
                                                    >
                                                        →{' '}
                                                        {
                                                            quo.invoice
                                                                .invoice_number
                                                        }
                                                    </Link>
                                                )}
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-xs font-semibold">
                                                {quo.ticket_number ?? '-'} ·{' '}
                                                {quo.customer_name}
                                            </p>
                                            <p className="text-muted-foreground mt-0.5 text-[11px]">
                                                Berlaku hingga:{' '}
                                                {quo.valid_until_label ?? '-'}
                                                {quo.approved_at
                                                    ? ` · Disetujui: ${quo.approved_at}`
                                                    : ''}
                                                {quo.rejected_at
                                                    ? ` · Ditolak: ${quo.rejected_at}`
                                                    : ''}
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-3">
                                            <div className="text-right">
                                                <p className="text-foreground text-base font-black">
                                                    {money.format(quo.total)}
                                                </p>
                                                <p className="text-muted-foreground text-[10px]">
                                                    Pajak {quo.tax_rate}%
                                                </p>
                                            </div>
                                            <Link
                                                href={quotationShow.url(quo.id)}
                                                className="bg-primary text-primary-foreground rounded-full px-4 py-2 text-xs font-black transition hover:opacity-90"
                                            >
                                                Detail
                                            </Link>
                                        </div>
                                    </div>
                                );
                            })}

                            {quotations.data.length === 0 && (
                                <div className="border-border grid min-h-48 place-items-center rounded-[20px] border border-dashed text-center">
                                    <div>
                                        <FileSpreadsheet className="text-muted-foreground/30 mx-auto size-7" />
                                        <p className="text-foreground mt-3 text-sm font-black">
                                            Belum ada penawaran biaya
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            Penawaran yang dibuat atau dikirim
                                            akan muncul di sini.
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>

                        {quotations.last_page > 1 && (
                            <div className="border-border mt-5 flex items-center justify-between border-t pt-4 text-xs">
                                <span className="text-muted-foreground font-bold">
                                    Halaman {quotations.current_page} dari{' '}
                                    {quotations.last_page}
                                </span>
                                <div className="flex gap-1.5">
                                    {quotations.links.map((link, idx) => (
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

QuotationsIndexPage.layout = {
    breadcrumbs: [
        { title: 'Keuangan', href: financeIndex() },
        { title: 'Penawaran Biaya', href: quotationsIndex() },
    ],
};

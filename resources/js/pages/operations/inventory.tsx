import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownToLine,
    ArrowUpFromLine,
    Boxes,
    CheckCircle2,
    CircleDollarSign,
    ClipboardList,
    Filter,
    PackagePlus,
    Pencil,
    RotateCcw,
    Search,
    SlidersHorizontal,
    Warehouse,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    index as inventoryIndex,
    store as storeItem,
    update as updateItemRoute,
} from '@/routes/inventory';
import { store as storeMovement } from '@/routes/inventory/movements';
import {
    cancel as cancelSparePartRoute,
    issue as issueSparePartRoute,
    returnMethod as returnSparePartRoute,
} from '@/routes/orders/spare-parts';

type Item = {
    id: number;
    sku: string;
    name: string;
    category: string;
    description?: string | null;
    unit: string;
    quantity_on_hand: number;
    reorder_level: number;
    warranty_days?: number | null;
    warranty_terms?: string | null;
    unit_cost: number;
    selling_price?: number | null;
    storage_location: string | null;
    supplier_name?: string | null;
    supplier_contact?: string | null;
    is_active?: boolean;
    is_low_stock: boolean;
    is_out_of_stock?: boolean;
    stock_status?: 'out_of_stock' | 'low_stock' | 'normal';
    total_value?: number;
};

type Movement = {
    id: number;
    item: string;
    sku: string;
    unit: string;
    type:
        | 'stock_in'
        | 'stock_out'
        | 'adjustment_in'
        | 'adjustment_out'
        | 'spare_part_issue'
        | 'spare_part_return';
    quantity: number;
    balance_before?: number | null;
    balance_after: number;
    unit_cost?: number | null;
    reference?: string | null;
    supplier_name?: string | null;
    adjustment_reason?: string | null;
    order: string | null;
    actor: string;
    notes: string | null;
    financial_transaction_id?: number | null;
    occurred_at: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};

type SparePartRequestRow = {
    id: number;
    service_order_id: string;
    ticket_number: string;
    customer_name: string;
    inventory_item_id: number;
    sku: string;
    item_name: string;
    unit: string;
    available_stock: number;
    requested_quantity: number;
    issued_quantity: number;
    returned_quantity: number;
    net_issued_quantity: number;
    unit_cost: number;
    unit_price: number;
    status:
        | 'requested'
        | 'issued'
        | 'partially_returned'
        | 'returned'
        | 'cancelled';
    requester: string;
    approver: string | null;
    returner: string | null;
    request_notes: string | null;
    issue_notes: string | null;
    return_notes: string | null;
    cancellation_reason: string | null;
    requested_at: string;
    issued_at: string | null;
    returned_at: string | null;
    can_issue: boolean;
    can_return: boolean;
    can_cancel: boolean;
};

type OrderUsageRow = {
    service_order_id: string;
    ticket_number: string;
    customer_name: string;
    items_count: number;
    net_units: number;
    total_cost: number;
    total_chargeable: number;
};

type LowStockAlert = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    quantity_on_hand: number;
    reorder_level: number;
    is_out_of_stock: boolean;
};

type ActiveItemOption = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    quantity_on_hand: number;
    unit_cost: number;
    supplier_name: string | null;
};

type OrderOption = { id: string; ticket_number: string; customer_name: string };

type Filters = {
    search: string;
    category: string;
    stock_status: string;
    sort: string;
    movement_type: string;
};

const money = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const MOVEMENT_TYPE_LABELS: Record<
    Movement['type'],
    { label: string; badge: string; sign: '+' | '-' }
> = {
    stock_in: {
        label: 'Barang Masuk',
        badge: 'status-pill-success',
        sign: '+',
    },
    stock_out: {
        label: 'Barang Keluar',
        badge: 'status-pill-danger',
        sign: '-',
    },
    adjustment_in: {
        label: 'Penyesuaian (+)',
        badge: 'status-pill-info',
        sign: '+',
    },
    adjustment_out: {
        label: 'Penyesuaian (-)',
        badge: 'status-pill-warning',
        sign: '-',
    },
    spare_part_issue: {
        label: 'Keluar Spare-Part',
        badge: 'status-pill-danger',
        sign: '-',
    },
    spare_part_return: {
        label: 'Retur Spare-Part',
        badge: 'status-pill-info',
        sign: '+',
    },
};

const SPARE_PART_STATUS_BADGES: Record<
    SparePartRequestRow['status'],
    { label: string; className: string }
> = {
    requested: {
        label: 'Menunggu Gudang',
        className: 'status-pill-warning',
    },
    issued: {
        label: 'Dikeluarkan',
        className: 'status-pill-success',
    },
    partially_returned: {
        label: 'Retur Sebagian',
        className: 'status-pill-info',
    },
    returned: {
        label: 'Diretur Penuh',
        className: 'border border-border bg-muted text-muted-foreground',
    },
    cancelled: {
        label: 'Dibatalkan',
        className: 'status-pill-danger',
    },
};

export default function InventoryPage({
    role,
    canManage,
    canRecordFinance = false,
    stats,
    filters = {
        search: '',
        category: '',
        stock_status: 'all',
        sort: 'category_name',
        movement_type: '',
    },
    categories = [],
    lowStockAlerts = [],
    allActiveItems,
    items,
    movements,
    sparePartRequests,
    orderUsage = [],
    orders,
}: {
    role: string;
    canManage: boolean;
    canRecordFinance?: boolean;
    stats: {
        item_types: number;
        inactive_types?: number;
        units: number;
        low_stock: number;
        low_warning_only?: number;
        out_of_stock?: number;
        asset_value: number;
        retail_value?: number;
        valuation_method?: string;
        valuation_method_label?: string;
        movement_summary?: {
            stock_in_units: number;
            stock_out_units: number;
            adjustment_net_units: number;
            spare_part_issued_units: number;
            spare_part_returned_units: number;
        };
        finance_summary?: {
            verified_purchase_expense: number;
            pending_purchase_expense: number;
        };
    };
    filters?: Filters;
    categories?: string[];
    lowStockAlerts?: LowStockAlert[];
    allActiveItems?: ActiveItemOption[];
    items: Item[];
    movements: Movement[] | Paginated<Movement>;
    sparePartRequests?: Paginated<SparePartRequestRow>;
    orderUsage?: OrderUsageRow[];
    orders: OrderOption[];
}) {
    const [filterState, setFilterState] = useState<Filters>(filters);
    const [editingItem, setEditingItem] = useState<Item | null>(null);

    const activeCatalog: ActiveItemOption[] =
        allActiveItems ??
        items.map((i) => ({
            id: i.id,
            sku: i.sku,
            name: i.name,
            unit: i.unit,
            quantity_on_hand: i.quantity_on_hand,
            unit_cost: i.unit_cost,
            supplier_name: i.supplier_name ?? null,
        }));

    const movementList: Movement[] = Array.isArray(movements)
        ? movements
        : movements.data;
    const movementPagination = Array.isArray(movements) ? null : movements;

    const itemForm = useForm({
        sku: '',
        name: '',
        category: 'Jaringan',
        description: '',
        unit: 'unit',
        quantity_on_hand: '0',
        reorder_level: '0',
        warranty_days: '',
        warranty_terms: '',
        unit_cost: '0',
        selling_price: '',
        storage_location: '',
        supplier_name: '',
        supplier_contact: '',
    });

    const editForm = useForm({
        sku: '',
        name: '',
        category: '',
        description: '',
        unit: 'unit',
        reorder_level: '0',
        warranty_days: '',
        warranty_terms: '',
        unit_cost: '0',
        selling_price: '',
        storage_location: '',
        supplier_name: '',
        supplier_contact: '',
        is_active: true,
    });

    const movementForm = useForm({
        inventory_item_id: activeCatalog[0]?.id.toString() ?? '',
        service_order_id: '',
        type: 'stock_in',
        quantity: '1',
        new_quantity: '',
        unit_cost: '',
        reference: '',
        supplier_name: '',
        adjustment_reason: '',
        notes: '',
        occurred_at: '',
        record_finance_expense: false,
        payment_method: 'bank_transfer',
    });

    const applyFilters = (next: Filters) => {
        setFilterState(next);
        const query = Object.fromEntries(
            Object.entries(next).filter(([, value]) => value.trim() !== ''),
        );
        router.get(
            inventoryIndex.url({ query }),
            {},
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        applyFilters({
            search: '',
            category: '',
            stock_status: 'all',
            sort: 'category_name',
            movement_type: '',
        });
    };

    function openEditModal(item: Item) {
        setEditingItem(item);
        editForm.setData({
            sku: item.sku,
            name: item.name,
            category: item.category,
            description: item.description ?? '',
            unit: item.unit,
            reorder_level: String(item.reorder_level),
            warranty_days:
                item.warranty_days !== null && item.warranty_days !== undefined
                    ? String(item.warranty_days)
                    : '',
            warranty_terms: item.warranty_terms ?? '',
            unit_cost: String(item.unit_cost),
            selling_price:
                item.selling_price !== null && item.selling_price !== undefined
                    ? String(item.selling_price)
                    : '',
            storage_location: item.storage_location ?? '',
            supplier_name: item.supplier_name ?? '',
            supplier_contact: item.supplier_contact ?? '',
            is_active: item.is_active ?? true,
        });
        editForm.clearErrors();
    }

    function submitEditItem(event: FormEvent) {
        event.preventDefault();
        if (!editingItem) {
            return;
        }
        editForm.patch(updateItemRoute.url(editingItem.id), {
            preserveScroll: true,
            onSuccess: () => setEditingItem(null),
        });
    }

    function submitItem(event: FormEvent) {
        event.preventDefault();
        itemForm.post(storeItem.url(), {
            preserveScroll: true,
            onSuccess: () => itemForm.reset(),
        });
    }

    function submitMovement(event: FormEvent) {
        event.preventDefault();
        movementForm.post(storeMovement.url(), {
            preserveScroll: true,
            onSuccess: () =>
                movementForm.reset(
                    'quantity',
                    'new_quantity',
                    'unit_cost',
                    'reference',
                    'supplier_name',
                    'adjustment_reason',
                    'notes',
                    'occurred_at',
                    'record_finance_expense',
                ),
        });
    }

    const cards = [
        [
            'Jenis barang aktif',
            stats.item_types,
            stats.inactive_types
                ? `+${stats.inactive_types} nonaktif`
                : 'Semua item aktif terpantau',
            Boxes,
        ],
        [
            'Total unit tersedia',
            stats.units,
            'Saldo fisik seluruh gudang',
            Warehouse,
        ],
        [
            'Perlu restock / habis',
            stats.low_stock,
            `${stats.low_warning_only ?? stats.low_stock} menipis · ${stats.out_of_stock ?? 0} habis`,
            AlertTriangle,
        ],
        [
            'Nilai persediaan',
            money.format(stats.asset_value),
            stats.valuation_method_label ?? 'Weighted Average Cost (WAC)',
            CircleDollarSign,
        ],
    ] as const;

    const selectedCatalogItem = activeCatalog.find(
        (i) => i.id.toString() === movementForm.data.inventory_item_id,
    );

    return (
        <>
            <Head title="Inventaris & Pengadaan" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl space-y-5">
                    <header className="liquid-panel-dark overflow-hidden rounded-[28px] p-6 text-white sm:p-8">
                        <p className="text-cyan text-xs font-black tracking-[.15em] uppercase">
                            Warehouse &amp; spare-part intelligence
                        </p>
                        <div className="mt-3 flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                            <div>
                                <h1 className="text-3xl font-black tracking-[-.045em] sm:text-4xl">
                                    Stok terkontrol. Pengeluaran terverifikasi.
                                </h1>
                                <p className="mt-3 max-w-2xl text-sm leading-6 text-white/58">
                                    Kelola master barang, mutasi
                                    masuk/keluar/penyesuaian, permintaan
                                    spare-part teknisi, serta valuasi gudang
                                    berbasis Weighted Average Cost.
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="border-cyan/25 bg-cyan/10 text-cyan rounded-full border px-4 py-2 text-xs font-black">
                                    Akses: {role}
                                </span>
                            </div>
                        </div>
                    </header>

                    <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                        {cards.map(([label, value, sub, Icon]) => (
                            <article
                                key={label}
                                className="liquid-panel rounded-[22px] p-4 sm:p-5"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs font-bold">
                                            {label}
                                        </p>
                                        <p className="text-foreground mt-2 text-2xl font-black tracking-[-.04em]">
                                            {value}
                                        </p>
                                        <p className="text-muted-foreground/85 mt-1 text-[11px] font-medium">
                                            {sub}
                                        </p>
                                    </div>
                                    <span className="bg-cyan/10 text-cyan grid size-10 shrink-0 place-items-center rounded-xl">
                                        <Icon className="size-5" />
                                    </span>
                                </div>
                            </article>
                        ))}
                    </section>

                    {(stats.movement_summary || canRecordFinance) && (
                        <section className="liquid-panel rounded-[24px] p-5">
                            <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        Ringkasan pergerakan stok
                                    </p>
                                    <div className="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-5">
                                        <MiniMetric
                                            label="Masuk"
                                            value={`+${stats.movement_summary?.stock_in_units ?? 0}`}
                                        />
                                        <MiniMetric
                                            label="Keluar Manual"
                                            value={`-${stats.movement_summary?.stock_out_units ?? 0}`}
                                        />
                                        <MiniMetric
                                            label="Penyesuaian"
                                            value={
                                                (stats.movement_summary
                                                    ?.adjustment_net_units ??
                                                    0) >= 0
                                                    ? `+${stats.movement_summary?.adjustment_net_units ?? 0}`
                                                    : String(
                                                          stats.movement_summary
                                                              ?.adjustment_net_units ??
                                                              0,
                                                      )
                                            }
                                        />
                                        <MiniMetric
                                            label="Keluar Tiket"
                                            value={`-${stats.movement_summary?.spare_part_issued_units ?? 0}`}
                                        />
                                        <MiniMetric
                                            label="Retur Tiket"
                                            value={`+${stats.movement_summary?.spare_part_returned_units ?? 0}`}
                                        />
                                    </div>
                                </div>

                                <div className="border-border border-t pt-3 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
                                    <p className="section-kicker text-cyan">
                                        Valuasi &amp; pembelian
                                    </p>
                                    <div className="mt-2.5 space-y-1.5 text-xs">
                                        <div className="flex items-center justify-between">
                                            <span className="text-muted-foreground">
                                                Potensi nilai jual
                                            </span>
                                            <span className="text-foreground font-black">
                                                {money.format(
                                                    stats.retail_value ??
                                                        stats.asset_value,
                                                )}
                                            </span>
                                        </div>
                                        {canRecordFinance &&
                                            stats.finance_summary && (
                                                <>
                                                    <div className="flex items-center justify-between">
                                                        <span className="text-muted-foreground">
                                                            Beban pembelian
                                                            terverifikasi
                                                        </span>
                                                        <span className="font-bold text-emerald-700 dark:text-emerald-400">
                                                            {money.format(
                                                                stats
                                                                    .finance_summary
                                                                    .verified_purchase_expense,
                                                            )}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center justify-between">
                                                        <span className="text-muted-foreground">
                                                            Beban pembelian
                                                            pending
                                                        </span>
                                                        <span className="font-bold text-amber-700 dark:text-amber-300">
                                                            {money.format(
                                                                stats
                                                                    .finance_summary
                                                                    .pending_purchase_expense,
                                                            )}
                                                        </span>
                                                    </div>
                                                </>
                                            )}
                                    </div>
                                </div>
                            </div>
                        </section>
                    )}

                    {lowStockAlerts.length > 0 && (
                        <section className="rounded-[22px] border border-rose-500/25 bg-rose-500/10 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2 text-rose-800 dark:text-rose-200">
                                    <AlertTriangle className="size-4 shrink-0" />
                                    <h2 className="text-sm font-black">
                                        Peringatan Stok Minimum (
                                        {lowStockAlerts.length} item)
                                    </h2>
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        applyFilters({
                                            ...filterState,
                                            stock_status: 'low_stock',
                                        })
                                    }
                                    className="text-xs font-bold text-rose-700 underline dark:text-rose-300"
                                >
                                    Tampilkan stok menipis saja
                                </button>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {lowStockAlerts.map((alert) => (
                                    <span
                                        key={alert.id}
                                        className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-bold ${
                                            alert.is_out_of_stock
                                                ? 'status-pill-danger'
                                                : 'status-pill-warning'
                                        }`}
                                    >
                                        <span className="font-mono text-[10px]">
                                            {alert.sku}
                                        </span>
                                        {alert.name}: {alert.quantity_on_hand}{' '}
                                        {alert.unit} (min {alert.reorder_level})
                                    </span>
                                ))}
                            </div>
                        </section>
                    )}

                    {canManage && (
                        <section className="grid gap-5 xl:grid-cols-2">
                            <form
                                onSubmit={submitMovement}
                                className="liquid-panel rounded-[26px] p-5 sm:p-6"
                            >
                                <div className="flex items-center gap-3">
                                    <span className="bg-cyan grid size-10 place-items-center rounded-xl text-white">
                                        <ArrowDownToLine className="size-5" />
                                    </span>
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Mutasi &amp; penyesuaian stok
                                        </p>
                                        <h2 className="text-ink font-black">
                                            Catat masuk, keluar, atau koreksi
                                        </h2>
                                    </div>
                                </div>
                                <div className="mt-5 grid gap-3.5 sm:grid-cols-2">
                                    <Field
                                        label="Barang"
                                        error={
                                            movementForm.errors
                                                .inventory_item_id
                                        }
                                        wide
                                    >
                                        <select
                                            className="liquid-input h-11 w-full rounded-xl px-3 text-sm"
                                            value={
                                                movementForm.data
                                                    .inventory_item_id
                                            }
                                            onChange={(event) => {
                                                const nextId =
                                                    event.target.value;
                                                const found =
                                                    activeCatalog.find(
                                                        (i) =>
                                                            i.id.toString() ===
                                                            nextId,
                                                    );
                                                movementForm.setData({
                                                    ...movementForm.data,
                                                    inventory_item_id: nextId,
                                                    new_quantity: found
                                                        ? String(
                                                              found.quantity_on_hand,
                                                          )
                                                        : '',
                                                });
                                            }}
                                        >
                                            {activeCatalog.map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.sku} · {item.name}{' '}
                                                    (Stok{' '}
                                                    {item.quantity_on_hand}{' '}
                                                    {item.unit})
                                                </option>
                                            ))}
                                        </select>
                                    </Field>

                                    <Field
                                        label="Jenis mutasi"
                                        error={movementForm.errors.type}
                                    >
                                        <select
                                            className="liquid-input h-11 w-full rounded-xl px-3 text-sm"
                                            value={movementForm.data.type}
                                            onChange={(event) =>
                                                movementForm.setData(
                                                    'type',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="stock_in">
                                                Barang masuk
                                            </option>
                                            <option value="stock_out">
                                                Barang keluar
                                            </option>
                                            <option value="adjustment">
                                                Penyesuaian / stok opname
                                            </option>
                                        </select>
                                    </Field>

                                    {movementForm.data.type === 'adjustment' ? (
                                        <Field
                                            label={`Saldo baru (${selectedCatalogItem ? `saat ini ${selectedCatalogItem.quantity_on_hand} ${selectedCatalogItem.unit}` : 'unit'})`}
                                            error={
                                                movementForm.errors.new_quantity
                                            }
                                        >
                                            <Input
                                                type="number"
                                                min="0"
                                                value={
                                                    movementForm.data
                                                        .new_quantity
                                                }
                                                onChange={(event) =>
                                                    movementForm.setData(
                                                        'new_quantity',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    ) : (
                                        <Field
                                            label="Jumlah"
                                            error={movementForm.errors.quantity}
                                        >
                                            <Input
                                                type="number"
                                                min="1"
                                                value={
                                                    movementForm.data.quantity
                                                }
                                                onChange={(event) =>
                                                    movementForm.setData(
                                                        'quantity',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    )}

                                    {movementForm.data.type === 'stock_in' && (
                                        <>
                                            <Field
                                                label="Harga beli/unit (WAC)"
                                                error={
                                                    movementForm.errors
                                                        .unit_cost
                                                }
                                            >
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    placeholder={
                                                        selectedCatalogItem
                                                            ? String(
                                                                  selectedCatalogItem.unit_cost,
                                                              )
                                                            : '0'
                                                    }
                                                    value={
                                                        movementForm.data
                                                            .unit_cost
                                                    }
                                                    onChange={(event) =>
                                                        movementForm.setData(
                                                            'unit_cost',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Supplier (opsional)"
                                                error={
                                                    movementForm.errors
                                                        .supplier_name
                                                }
                                            >
                                                <Input
                                                    value={
                                                        movementForm.data
                                                            .supplier_name
                                                    }
                                                    onChange={(event) =>
                                                        movementForm.setData(
                                                            'supplier_name',
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="PT Mitra Jaringan"
                                                />
                                            </Field>
                                        </>
                                    )}

                                    {movementForm.data.type ===
                                        'adjustment' && (
                                        <Field
                                            label="Alasan penyesuaian (wajib)"
                                            error={
                                                movementForm.errors
                                                    .adjustment_reason
                                            }
                                            wide
                                        >
                                            <Input
                                                value={
                                                    movementForm.data
                                                        .adjustment_reason
                                                }
                                                onChange={(event) =>
                                                    movementForm.setData(
                                                        'adjustment_reason',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Hasil stock opname bulanan / barang rusak"
                                            />
                                        </Field>
                                    )}

                                    <Field
                                        label="Referensi / PO / DO"
                                        error={movementForm.errors.reference}
                                    >
                                        <Input
                                            value={movementForm.data.reference}
                                            onChange={(event) =>
                                                movementForm.setData(
                                                    'reference',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="PO-2026-014"
                                        />
                                    </Field>

                                    <Field
                                        label="Tanggal transaksi (opsional)"
                                        error={movementForm.errors.occurred_at}
                                    >
                                        <Input
                                            type="datetime-local"
                                            value={
                                                movementForm.data.occurred_at
                                            }
                                            onChange={(event) =>
                                                movementForm.setData(
                                                    'occurred_at',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>

                                    {movementForm.data.type !==
                                        'adjustment' && (
                                        <Field
                                            label="Tiket terkait (opsional)"
                                            error={
                                                movementForm.errors
                                                    .service_order_id
                                            }
                                            wide
                                        >
                                            <select
                                                className="liquid-input h-11 w-full rounded-xl px-3 text-sm"
                                                value={
                                                    movementForm.data
                                                        .service_order_id
                                                }
                                                onChange={(event) =>
                                                    movementForm.setData(
                                                        'service_order_id',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Tidak terkait tiket
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
                                    )}

                                    <Field
                                        label="Catatan tambahan"
                                        error={movementForm.errors.notes}
                                        wide
                                    >
                                        <Input
                                            value={movementForm.data.notes}
                                            onChange={(event) =>
                                                movementForm.setData(
                                                    'notes',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Keterangan mutasi atau tujuan pengeluaran"
                                        />
                                    </Field>

                                    {canRecordFinance &&
                                        movementForm.data.type ===
                                            'stock_in' && (
                                            <div className="surface-tile rounded-2xl p-3 text-xs sm:col-span-2">
                                                <label className="text-foreground flex cursor-pointer items-center gap-2 font-bold">
                                                    <input
                                                        type="checkbox"
                                                        checked={
                                                            movementForm.data
                                                                .record_finance_expense
                                                        }
                                                        onChange={(event) =>
                                                            movementForm.setData(
                                                                'record_finance_expense',
                                                                event.target
                                                                    .checked,
                                                            )
                                                        }
                                                        className="accent-cyan size-4"
                                                    />
                                                    Catat otomatis ke Keuangan
                                                    (status pending verifikasi
                                                    operator)
                                                </label>
                                                {movementForm.data
                                                    .record_finance_expense && (
                                                    <select
                                                        value={
                                                            movementForm.data
                                                                .payment_method
                                                        }
                                                        onChange={(event) =>
                                                            movementForm.setData(
                                                                'payment_method',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        className="liquid-input mt-2 h-9 w-full rounded-xl px-3 text-xs"
                                                    >
                                                        <option value="bank_transfer">
                                                            Transfer Bank
                                                        </option>
                                                        <option value="cash">
                                                            Tunai
                                                        </option>
                                                        <option value="qr">
                                                            QRIS
                                                        </option>
                                                        <option value="other">
                                                            Lainnya
                                                        </option>
                                                    </select>
                                                )}
                                            </div>
                                        )}
                                </div>
                                <Button
                                    className="mt-5 h-11 rounded-full px-6"
                                    disabled={
                                        movementForm.processing ||
                                        activeCatalog.length === 0
                                    }
                                >
                                    <ArrowUpFromLine /> Simpan mutasi
                                </Button>
                            </form>

                            <form
                                onSubmit={submitItem}
                                className="liquid-panel rounded-[26px] p-5 sm:p-6"
                            >
                                <div className="flex items-center gap-3">
                                    <span className="bg-primary text-primary-foreground grid size-10 place-items-center rounded-xl shadow-sm">
                                        <PackagePlus className="size-5" />
                                    </span>
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Master data inventaris
                                        </p>
                                        <h2 className="text-ink font-black">
                                            Tambah jenis barang baru
                                        </h2>
                                    </div>
                                </div>
                                <div className="mt-5 grid gap-3.5 sm:grid-cols-2">
                                    <Field
                                        label="SKU"
                                        error={itemForm.errors.sku}
                                    >
                                        <Input
                                            value={itemForm.data.sku}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'sku',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                            placeholder="NET-AP-001"
                                        />
                                    </Field>
                                    <Field
                                        label="Nama barang"
                                        error={itemForm.errors.name}
                                    >
                                        <Input
                                            value={itemForm.data.name}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Kategori"
                                        error={itemForm.errors.category}
                                    >
                                        <Input
                                            value={itemForm.data.category}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'category',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Satuan"
                                        error={itemForm.errors.unit}
                                    >
                                        <Input
                                            value={itemForm.data.unit}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'unit',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Saldo awal"
                                        error={itemForm.errors.quantity_on_hand}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            value={
                                                itemForm.data.quantity_on_hand
                                            }
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'quantity_on_hand',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Batas minimum"
                                        error={itemForm.errors.reorder_level}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            value={itemForm.data.reorder_level}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'reorder_level',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Harga beli/unit"
                                        error={itemForm.errors.unit_cost}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            value={itemForm.data.unit_cost}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'unit_cost',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Harga jual/unit (opsional)"
                                        error={itemForm.errors.selling_price}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            placeholder="Opsional"
                                            value={itemForm.data.selling_price}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'selling_price',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Lokasi penyimpanan"
                                        error={itemForm.errors.storage_location}
                                    >
                                        <Input
                                            value={
                                                itemForm.data.storage_location
                                            }
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'storage_location',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Supplier"
                                        error={itemForm.errors.supplier_name}
                                    >
                                        <Input
                                            value={itemForm.data.supplier_name}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'supplier_name',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Opsional"
                                        />
                                    </Field>
                                    <Field
                                        label="Garansi default (hari)"
                                        error={itemForm.errors.warranty_days}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            value={itemForm.data.warranty_days}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'warranty_days',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Kosong = tanpa garansi"
                                        />
                                    </Field>
                                    <Field
                                        label="Cakupan garansi part"
                                        error={itemForm.errors.warranty_terms}
                                    >
                                        <Input
                                            value={itemForm.data.warranty_terms}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'warranty_terms',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Opsional"
                                        />
                                    </Field>
                                    <Field
                                        label="Deskripsi singkat"
                                        error={itemForm.errors.description}
                                        wide
                                    >
                                        <Input
                                            value={itemForm.data.description}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Spesifikasi singkat item"
                                        />
                                    </Field>
                                </div>
                                <Button
                                    variant="secondary"
                                    className="mt-5 h-11 rounded-full px-6"
                                    disabled={itemForm.processing}
                                >
                                    <PackagePlus /> Tambah barang
                                </Button>
                            </form>
                        </section>
                    )}

                    {sparePartRequests && (
                        <section className="grid gap-5 xl:grid-cols-[1.3fr_.7fr]">
                            <div className="liquid-panel rounded-[26px] p-5 sm:p-6">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Spare-part fulfillment
                                        </p>
                                        <h2 className="text-ink mt-1 text-xl font-black">
                                            Permintaan &amp; Retur Spare-Part
                                            Tiket
                                        </h2>
                                    </div>
                                    <ClipboardList className="text-cyan size-5" />
                                </div>

                                {sparePartRequests.data.length === 0 ? (
                                    <p className="text-muted-foreground mt-4 text-xs">
                                        Belum ada permintaan spare-part dari
                                        tiket servis.
                                    </p>
                                ) : (
                                    <div className="mt-4 space-y-3">
                                        {sparePartRequests.data.map((req) => (
                                            <SparePartQueueRow
                                                key={req.id}
                                                row={req}
                                            />
                                        ))}
                                    </div>
                                )}

                                <PaginationBar
                                    links={sparePartRequests.links}
                                    currentPage={sparePartRequests.current_page}
                                    lastPage={sparePartRequests.last_page}
                                />
                            </div>

                            <div className="liquid-panel rounded-[26px] p-5 sm:p-6">
                                <p className="section-kicker text-cyan">
                                    Konsumsi per tiket
                                </p>
                                <h2 className="text-foreground mt-1 text-xl font-black">
                                    Pemakaian per Service Order
                                </h2>
                                {orderUsage.length === 0 ? (
                                    <p className="text-muted-foreground mt-4 text-xs">
                                        Belum ada pengeluaran spare-part
                                        terkonfirmasi pada tiket aktif/selesai.
                                    </p>
                                ) : (
                                    <div className="mt-4 space-y-2.5">
                                        {orderUsage.map((usage) => (
                                            <div
                                                key={usage.service_order_id}
                                                className="liquid-control rounded-2xl p-3.5 text-xs"
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-foreground font-black">
                                                        {usage.ticket_number}
                                                    </span>
                                                    <span className="bg-primary text-primary-foreground rounded-full px-2 py-0.5 text-[10px] font-bold">
                                                        {usage.net_units} unit
                                                        bersih
                                                    </span>
                                                </div>
                                                <p className="text-muted-foreground mt-0.5">
                                                    {usage.customer_name} ·{' '}
                                                    {usage.items_count} item
                                                </p>
                                                <div className="border-border mt-2 flex items-center justify-between border-t pt-2">
                                                    <span className="text-muted-foreground">
                                                        HPP Modal:{' '}
                                                        <strong className="text-foreground">
                                                            {money.format(
                                                                usage.total_cost,
                                                            )}
                                                        </strong>
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        Tagih:{' '}
                                                        <strong className="text-foreground">
                                                            {money.format(
                                                                usage.total_chargeable,
                                                            )}
                                                        </strong>
                                                    </span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </section>
                    )}

                    <section className="liquid-panel overflow-hidden rounded-[26px]">
                        <div className="border-border border-b p-5 sm:p-6">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        Stock board
                                    </p>
                                    <h2 className="text-foreground mt-1 text-xl font-black">
                                        Ketersediaan &amp; Master Barang (
                                        {items.length})
                                    </h2>
                                </div>
                                <div className="grid gap-2.5 sm:grid-cols-4">
                                    <div className="relative">
                                        <Search className="text-muted-foreground absolute top-1/2 left-3 size-3.5 -translate-y-1/2" />
                                        <input
                                            type="text"
                                            value={filterState.search}
                                            onChange={(e) =>
                                                applyFilters({
                                                    ...filterState,
                                                    search: e.target.value,
                                                })
                                            }
                                            placeholder="Cari SKU, nama, rak..."
                                            className="liquid-input h-9 w-full rounded-xl pr-3 pl-8 text-xs"
                                        />
                                    </div>
                                    <select
                                        value={filterState.category}
                                        onChange={(e) =>
                                            applyFilters({
                                                ...filterState,
                                                category: e.target.value,
                                            })
                                        }
                                        className="liquid-input h-9 rounded-xl px-3 text-xs font-semibold"
                                    >
                                        <option value="">Semua kategori</option>
                                        {categories.map((cat) => (
                                            <option key={cat} value={cat}>
                                                {cat}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        value={filterState.stock_status}
                                        onChange={(e) =>
                                            applyFilters({
                                                ...filterState,
                                                stock_status: e.target.value,
                                            })
                                        }
                                        className="liquid-input h-9 rounded-xl px-3 text-xs font-semibold"
                                    >
                                        <option value="all">
                                            Semua stok aktif
                                        </option>
                                        <option value="low_stock">
                                            Stok menipis
                                        </option>
                                        <option value="out_of_stock">
                                            Stok habis (0)
                                        </option>
                                        <option value="normal">
                                            Stok aman
                                        </option>
                                        <option value="inactive">
                                            Barang nonaktif
                                        </option>
                                    </select>
                                    <div className="flex gap-1.5">
                                        <select
                                            value={filterState.sort}
                                            onChange={(e) =>
                                                applyFilters({
                                                    ...filterState,
                                                    sort: e.target.value,
                                                })
                                            }
                                            className="liquid-input h-9 w-full rounded-xl px-3 text-xs font-semibold"
                                        >
                                            <option value="category_name">
                                                Kategori &amp; Nama
                                            </option>
                                            <option value="stock_asc">
                                                Stok terendah
                                            </option>
                                            <option value="stock_desc">
                                                Stok tertinggi
                                            </option>
                                            <option value="value_desc">
                                                Nilai tertinggi
                                            </option>
                                            <option value="name_asc">
                                                Nama A-Z
                                            </option>
                                        </select>
                                        {(filterState.search ||
                                            filterState.category ||
                                            filterState.stock_status !==
                                                'all' ||
                                            filterState.movement_type) && (
                                            <button
                                                type="button"
                                                onClick={resetFilters}
                                                title="Reset filter"
                                                className="border-border bg-card text-foreground hover:bg-muted grid size-9 shrink-0 place-items-center rounded-xl border transition"
                                            >
                                                <RotateCcw className="size-3.5" />
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {editingItem && canManage && (
                            <form
                                onSubmit={submitEditItem}
                                className="border-border bg-muted/40 border-b p-5 sm:p-6"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <h3 className="text-foreground flex items-center gap-2 font-black">
                                        <SlidersHorizontal className="text-cyan size-4" />
                                        Edit Master Barang · {editingItem.sku}
                                    </h3>
                                    <button
                                        type="button"
                                        onClick={() => setEditingItem(null)}
                                        className="text-muted-foreground hover:bg-muted rounded-lg p-1"
                                    >
                                        <X className="size-4" />
                                    </button>
                                </div>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Saldo stok ({editingItem.quantity_on_hand}{' '}
                                    {editingItem.unit}) hanya boleh diubah
                                    melalui Mutasi atau Penyesuaian Stok agar
                                    jejak pergerakan tetap utuh.
                                </p>
                                <div className="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    <Field
                                        label="SKU"
                                        error={editForm.errors.sku}
                                    >
                                        <Input
                                            value={editForm.data.sku}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'sku',
                                                    e.target.value.toUpperCase(),
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Nama"
                                        error={editForm.errors.name}
                                    >
                                        <Input
                                            value={editForm.data.name}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Kategori"
                                        error={editForm.errors.category}
                                    >
                                        <Input
                                            value={editForm.data.category}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'category',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Satuan"
                                        error={editForm.errors.unit}
                                    >
                                        <Input
                                            value={editForm.data.unit}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'unit',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Batas minimum"
                                        error={editForm.errors.reorder_level}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            value={editForm.data.reorder_level}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'reorder_level',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Harga beli/unit"
                                        error={editForm.errors.unit_cost}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            value={editForm.data.unit_cost}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'unit_cost',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Harga jual/unit"
                                        error={editForm.errors.selling_price}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            value={editForm.data.selling_price}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'selling_price',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Lokasi"
                                        error={editForm.errors.storage_location}
                                    >
                                        <Input
                                            value={
                                                editForm.data.storage_location
                                            }
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'storage_location',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Supplier"
                                        error={editForm.errors.supplier_name}
                                    >
                                        <Input
                                            value={editForm.data.supplier_name}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'supplier_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Garansi default (hari)"
                                        error={editForm.errors.warranty_days}
                                    >
                                        <Input
                                            type="number"
                                            min="0"
                                            value={editForm.data.warranty_days}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'warranty_days',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Opsional"
                                        />
                                    </Field>
                                    <Field
                                        label="Cakupan garansi part"
                                        error={editForm.errors.warranty_terms}
                                    >
                                        <Input
                                            value={editForm.data.warranty_terms}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'warranty_terms',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Opsional"
                                        />
                                    </Field>
                                    <Field
                                        label="Status item"
                                        error={editForm.errors.is_active}
                                    >
                                        <select
                                            value={
                                                editForm.data.is_active
                                                    ? '1'
                                                    : '0'
                                            }
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'is_active',
                                                    e.target.value === '1',
                                                )
                                            }
                                            className="liquid-input h-10 w-full rounded-xl px-3 text-xs"
                                        >
                                            <option value="1">Aktif</option>
                                            <option value="0">Nonaktif</option>
                                        </select>
                                    </Field>
                                    <Field
                                        label="Deskripsi"
                                        error={editForm.errors.description}
                                        wide
                                    >
                                        <Input
                                            value={editForm.data.description}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'description',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="mt-4 flex gap-2">
                                    <Button
                                        size="sm"
                                        disabled={editForm.processing}
                                    >
                                        <CheckCircle2 className="size-3.5" />{' '}
                                        Simpan Perubahan
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditingItem(null)}
                                    >
                                        Batal
                                    </Button>
                                </div>
                            </form>
                        )}

                        <div className="bg-border grid gap-px sm:grid-cols-2 xl:grid-cols-3">
                            {items.length === 0 ? (
                                <div className="bg-card text-muted-foreground col-span-full p-8 text-center text-sm">
                                    Tidak ada barang inventaris yang sesuai
                                    dengan filter.
                                </div>
                            ) : (
                                items.map((item) => (
                                    <article
                                        key={item.id}
                                        className="bg-card p-5"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-cyan font-mono text-[10px] font-black">
                                                    {item.sku}
                                                </p>
                                                <h3 className="text-foreground mt-1 font-black">
                                                    {item.name}
                                                </h3>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    {item.category} ·{' '}
                                                    {item.storage_location ??
                                                        'Lokasi belum diisi'}
                                                </p>
                                                {item.supplier_name && (
                                                    <p className="text-muted-foreground/85 mt-0.5 text-[11px]">
                                                        Supplier:{' '}
                                                        {item.supplier_name}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex flex-col items-end gap-1.5">
                                                {item.is_active === false ? (
                                                    <span className="border-border bg-muted text-muted-foreground rounded-full border px-2.5 py-1 text-[10px] font-black">
                                                        NONAKTIF
                                                    </span>
                                                ) : item.is_out_of_stock ||
                                                  item.quantity_on_hand ===
                                                      0 ? (
                                                    <span className="rounded-full bg-red-600 px-2.5 py-1 text-[10px] font-black text-white">
                                                        HABIS
                                                    </span>
                                                ) : item.is_low_stock ? (
                                                    <span className="status-pill-danger rounded-full px-2.5 py-1 text-[10px] font-black">
                                                        RESTOCK
                                                    </span>
                                                ) : (
                                                    <span className="status-pill-success rounded-full px-2.5 py-1 text-[10px] font-black">
                                                        AMAN
                                                    </span>
                                                )}

                                                {canManage && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            openEditModal(item)
                                                        }
                                                        className="border-border bg-background text-foreground hover:bg-muted inline-flex items-center gap-1 rounded-lg border px-2 py-0.5 text-[11px] font-bold transition"
                                                    >
                                                        <Pencil className="size-3" />{' '}
                                                        Edit
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                        {item.description && (
                                            <p className="text-muted-foreground mt-2 line-clamp-2 text-xs">
                                                {item.description}
                                            </p>
                                        )}
                                        <div className="mt-5 flex items-end justify-between">
                                            <div>
                                                <p className="text-foreground text-3xl font-black">
                                                    {item.quantity_on_hand}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {item.unit} tersedia · min{' '}
                                                    {item.reorder_level}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-muted-foreground text-xs font-bold">
                                                    Modal:{' '}
                                                    {money.format(
                                                        item.unit_cost,
                                                    )}
                                                </p>
                                                {item.selling_price !== null &&
                                                    item.selling_price !==
                                                        undefined && (
                                                        <p className="text-muted-foreground/85 text-[11px]">
                                                            Jual:{' '}
                                                            {money.format(
                                                                item.selling_price,
                                                            )}
                                                        </p>
                                                    )}
                                            </div>
                                        </div>
                                    </article>
                                ))
                            )}
                        </div>
                    </section>

                    <section className="liquid-panel overflow-hidden rounded-[26px]">
                        <div className="border-border flex flex-wrap items-end justify-between gap-3 border-b p-5 sm:p-6">
                            <div>
                                <p className="section-kicker text-cyan">
                                    Immutable stock ledger
                                </p>
                                <h2 className="text-foreground mt-1 text-xl font-black">
                                    Riwayat Pergerakan Stok
                                </h2>
                            </div>
                            <div className="flex items-center gap-2">
                                <Filter className="text-muted-foreground size-3.5" />
                                <select
                                    value={filterState.movement_type}
                                    onChange={(e) =>
                                        applyFilters({
                                            ...filterState,
                                            movement_type: e.target.value,
                                        })
                                    }
                                    className="liquid-input h-9 rounded-xl px-3 text-xs font-semibold"
                                >
                                    <option value="">Semua jenis mutasi</option>
                                    <option value="stock_in">
                                        Barang masuk
                                    </option>
                                    <option value="stock_out">
                                        Barang keluar
                                    </option>
                                    <option value="adjustment_in">
                                        Penyesuaian (+)
                                    </option>
                                    <option value="adjustment_out">
                                        Penyesuaian (-)
                                    </option>
                                    <option value="spare_part_issue">
                                        Keluar spare-part tiket
                                    </option>
                                    <option value="spare_part_return">
                                        Retur spare-part tiket
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div className="divide-border divide-y">
                            {movementList.length === 0 ? (
                                <p className="text-muted-foreground p-6 text-center text-xs">
                                    Belum ada riwayat pergerakan stok yang
                                    tercatat.
                                </p>
                            ) : (
                                movementList.map((movement) => {
                                    const meta =
                                        MOVEMENT_TYPE_LABELS[movement.type] ??
                                        MOVEMENT_TYPE_LABELS.stock_out;
                                    return (
                                        <article
                                            key={movement.id}
                                            className="grid gap-3 p-5 sm:grid-cols-[1fr_auto] sm:items-center"
                                        >
                                            <div className="flex items-start gap-3">
                                                <span
                                                    className={`grid size-9 shrink-0 place-items-center rounded-xl ${meta.badge}`}
                                                >
                                                    {meta.sign === '+' ? (
                                                        <ArrowDownToLine className="size-4" />
                                                    ) : (
                                                        <ArrowUpFromLine className="size-4" />
                                                    )}
                                                </span>
                                                <div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="text-foreground text-sm font-black">
                                                            {movement.item}
                                                        </p>
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${meta.badge}`}
                                                        >
                                                            {meta.label}
                                                        </span>
                                                        {movement.reference && (
                                                            <span className="text-muted-foreground font-mono text-[10px]">
                                                                Ref:{' '}
                                                                {
                                                                    movement.reference
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {movement.actor} ·{' '}
                                                        {movement.order ??
                                                            movement.supplier_name ??
                                                            'Gudang'}{' '}
                                                        · {movement.occurred_at}
                                                    </p>
                                                    {movement.adjustment_reason && (
                                                        <p className="mt-1 text-xs font-medium text-amber-800 dark:text-amber-300">
                                                            Alasan penyesuaian:{' '}
                                                            {
                                                                movement.adjustment_reason
                                                            }
                                                        </p>
                                                    )}
                                                    {movement.notes && (
                                                        <p className="text-muted-foreground mt-0.5 text-xs">
                                                            {movement.notes}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <p className="text-right text-sm font-black">
                                                {meta.sign}
                                                {movement.quantity}{' '}
                                                {movement.unit}
                                                <span className="text-muted-foreground mt-1 block text-[10px] font-medium">
                                                    {movement.balance_before !==
                                                        undefined &&
                                                    movement.balance_before !==
                                                        null
                                                        ? `${movement.balance_before} → ${movement.balance_after}`
                                                        : `Saldo ${movement.balance_after}`}
                                                </span>
                                            </p>
                                        </article>
                                    );
                                })
                            )}
                        </div>

                        {movementPagination && (
                            <div className="border-border border-t px-5 py-3">
                                <PaginationBar
                                    links={movementPagination.links}
                                    currentPage={
                                        movementPagination.current_page
                                    }
                                    lastPage={movementPagination.last_page}
                                />
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

function SparePartQueueRow({ row }: { row: SparePartRequestRow }) {
    const [issueQty, setIssueQty] = useState(String(row.requested_quantity));
    const [returnQty, setReturnQty] = useState(String(row.net_issued_quantity));
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const badge = SPARE_PART_STATUS_BADGES[row.status];

    const runIssue = () => {
        setBusy(true);
        router.patch(
            issueSparePartRoute.url({
                order: row.service_order_id,
                sparePart: row.id,
            }),
            {
                issued_quantity: Number(issueQty),
                issue_notes: note.trim() !== '' ? note.trim() : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => setNote(''),
                onFinish: () => setBusy(false),
            },
        );
    };

    const runReturn = () => {
        setBusy(true);
        router.patch(
            returnSparePartRoute.url({
                order: row.service_order_id,
                sparePart: row.id,
            }),
            {
                return_quantity: Number(returnQty),
                return_notes: note.trim() !== '' ? note.trim() : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => setNote(''),
                onFinish: () => setBusy(false),
            },
        );
    };

    const runCancel = () => {
        setBusy(true);
        router.patch(
            cancelSparePartRoute.url({
                order: row.service_order_id,
                sparePart: row.id,
            }),
            {
                cancellation_reason: note.trim() !== '' ? note.trim() : null,
            },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <article className="liquid-control rounded-2xl p-4 text-xs">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-cyan font-mono text-[11px] font-black">
                            {row.ticket_number}
                        </span>
                        <span className="text-foreground font-black">
                            {row.item_name}
                        </span>
                        <span className="text-muted-foreground">
                            ({row.sku})
                        </span>
                    </div>
                    <p className="text-muted-foreground mt-1">
                        Diminta: <strong>{row.requested_quantity}</strong>{' '}
                        {row.unit} · Dikeluarkan:{' '}
                        <strong>{row.issued_quantity}</strong> · Diretur:{' '}
                        <strong>{row.returned_quantity}</strong> · Stok gudang:{' '}
                        <strong>{row.available_stock}</strong>
                    </p>
                    <p className="text-muted-foreground/85 mt-0.5">
                        Pemohon: {row.requester} · {row.requested_at}
                        {row.approver ? ` · Disetujui: ${row.approver}` : ''}
                    </p>
                    {row.request_notes && (
                        <p className="text-muted-foreground mt-1">
                            Catatan pemohon: {row.request_notes}
                        </p>
                    )}
                </div>
                <span
                    className={`rounded-full border px-2.5 py-0.5 text-[11px] font-bold ${badge.className}`}
                >
                    {badge.label}
                </span>
            </div>

            {(row.can_issue || row.can_return || row.can_cancel) && (
                <div className="border-border mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                    {row.can_issue && (
                        <>
                            <input
                                type="number"
                                min={1}
                                max={Math.min(
                                    row.requested_quantity,
                                    Math.max(1, row.available_stock),
                                )}
                                value={issueQty}
                                onChange={(e) => setIssueQty(e.target.value)}
                                className="liquid-input h-8 w-20 rounded-lg px-2 text-xs font-bold"
                                aria-label="Jumlah keluar"
                            />
                            <input
                                type="text"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="Catatan gudang (opsional)"
                                className="liquid-input h-8 min-w-40 flex-1 rounded-lg px-2.5 text-xs"
                            />
                            <button
                                type="button"
                                disabled={busy || row.available_stock <= 0}
                                onClick={runIssue}
                                className="bg-primary text-primary-foreground rounded-lg px-3 py-1.5 font-bold hover:opacity-90 disabled:opacity-50"
                            >
                                Setujui &amp; Keluarkan
                            </button>
                        </>
                    )}

                    {row.can_return && (
                        <>
                            <input
                                type="number"
                                min={1}
                                max={row.net_issued_quantity}
                                value={returnQty}
                                onChange={(e) => setReturnQty(e.target.value)}
                                className="liquid-input h-8 w-20 rounded-lg px-2 text-xs font-bold"
                                aria-label="Jumlah retur"
                            />
                            <input
                                type="text"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="Alasan retur (opsional)"
                                className="liquid-input h-8 min-w-40 flex-1 rounded-lg px-2.5 text-xs"
                            />
                            <button
                                type="button"
                                disabled={busy}
                                onClick={runReturn}
                                className="status-pill-info rounded-lg px-3 py-1.5 font-bold hover:opacity-85 disabled:opacity-50"
                            >
                                Catat Retur
                            </button>
                        </>
                    )}

                    {row.can_cancel && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={runCancel}
                            className="status-pill-danger rounded-lg px-3 py-1.5 font-bold hover:opacity-85 disabled:opacity-50"
                        >
                            Batalkan
                        </button>
                    )}
                </div>
            )}
        </article>
    );
}

function PaginationBar({
    links,
    currentPage,
    lastPage,
}: {
    links: PaginationLink[];
    currentPage: number;
    lastPage: number;
}) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
            <span className="text-muted-foreground">
                Halaman {currentPage} dari {lastPage}
            </span>
            <div className="flex flex-wrap gap-1">
                {links.map((link, idx) =>
                    link.url ? (
                        <Link
                            key={idx}
                            href={link.url}
                            preserveScroll
                            className={`rounded-lg px-2.5 py-1 font-bold ${
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'border-border bg-card text-foreground hover:bg-muted border'
                            }`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ) : (
                        <span
                            key={idx}
                            className="text-muted-foreground/60 rounded-lg px-2.5 py-1"
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ),
                )}
            </div>
        </div>
    );
}

function MiniMetric({ label, value }: { label: string; value: string }) {
    return (
        <div className="surface-tile rounded-xl px-3 py-2">
            <p className="text-muted-foreground text-[11px] font-medium">
                {label}
            </p>
            <p className="text-foreground mt-0.5 text-sm font-black">{value}</p>
        </div>
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

InventoryPage.layout = {
    breadcrumbs: [{ title: 'Inventaris', href: inventoryIndex() }],
};

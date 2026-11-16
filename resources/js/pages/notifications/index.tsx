import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Archive,
    ArrowUpRight,
    Bell,
    Check,
    CheckCheck,
    Lock,
    MessageSquareText,
    RefreshCw,
    Search,
    ShieldAlert,
    SlidersHorizontal,
    Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    archive as archiveNotification,
    destroy as destroyNotification,
    index as notificationsIndex,
    read as markNotificationRead,
    readAll as markAllNotificationsRead,
} from '@/routes/notifications';
import { retry as retryDelivery } from '@/routes/notifications/deliveries';
import { update as updatePreferences } from '@/routes/notifications/preferences';

type InboxItem = {
    id: string;
    event_key: string;
    category: string;
    category_label: string;
    priority: 'normal' | 'high' | 'critical';
    title: string;
    body: string;
    href: string | null;
    href_accessible: boolean;
    channel_label: string;
    read_at: string | null;
    is_read: boolean;
    is_archived: boolean;
    created_at: string;
    created_at_relative: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedInbox = {
    data: InboxItem[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};

type PriorityAlert = {
    key: string;
    severity: 'high' | 'critical';
    category: string;
    title: string;
    description: string;
    count: number;
    href: string | null;
};

type ActivityEntry = {
    id: string;
    feed_type: 'activity' | 'audit';
    category: string;
    category_label: string;
    title: string;
    description: string;
    actor: string;
    href: string | null;
    occurred_at: string;
    occurred_at_relative: string;
};

type DeliveryItem = {
    id: number;
    event_key: string;
    channel: string;
    recipient_name: string;
    target: string;
    title: string;
    status: 'queued' | 'processing' | 'sent' | 'skipped' | 'failed';
    attempts: number;
    last_error: string | null;
    sent_at: string | null;
    retried_at: string | null;
    retrier_name: string | null;
    created_at: string | null;
    can_retry: boolean;
};

type PreferenceItem = {
    category: string;
    label: string;
    description: string;
    mandatory: boolean;
    in_app_enabled: boolean;
    whatsapp_enabled: boolean;
};

type TabKey = 'inbox' | 'activity' | 'deliveries' | 'preferences';

const priorityBadgeClass: Record<string, string> = {
    critical: 'status-pill-danger',
    high: 'status-pill-warning',
    normal: 'border border-border bg-muted text-muted-foreground',
};

const deliveryStatusBadge: Record<string, string> = {
    sent: 'status-pill-success',
    queued: 'status-pill-info',
    processing: 'status-pill-warning',
    skipped: 'border border-border bg-muted text-muted-foreground',
    failed: 'status-pill-danger',
};

const activityFilterLabels: Record<string, string> = {
    all: 'Semua aktivitas',
    orders: 'Pesanan',
    sla: 'SLA',
    workforce: 'Workforce',
    inventory: 'Inventaris',
    warranty: 'Garansi',
    audit: 'Log Audit',
};

export default function NotificationsIndex({
    inbox,
    stats,
    filters,
    categoryOptions,
    activityFilterOptions,
    priorityAlerts,
    activityFeed,
    deliveries,
    preferences,
    fonnteConfigured,
}: {
    inbox: PaginatedInbox;
    stats: {
        unread: number;
        high_priority_unread: number;
        archived: number;
        total_active: number;
    };
    filters: {
        search: string;
        category: string;
        status: string;
        priority: string;
        tab: string;
        activity_filter: string;
    };
    categoryOptions: { value: string; label: string; mandatory: boolean }[];
    activityFilterOptions: string[];
    priorityAlerts: PriorityAlert[];
    activityFeed: ActivityEntry[];
    deliveries: {
        authorized: boolean;
        can_retry: boolean;
        queue_connection: string;
        pending_jobs: number;
        failed_jobs: number;
        items: DeliveryItem[];
    };
    preferences: PreferenceItem[];
    fonnteConfigured: boolean;
}) {
    const initialTab: TabKey =
        filters.tab === 'activity' ||
        filters.tab === 'preferences' ||
        (filters.tab === 'deliveries' && deliveries.authorized)
            ? filters.tab
            : 'inbox';

    const [activeTab, setActiveTab] = useState<TabKey>(initialTab);
    const [searchText, setSearchText] = useState(filters.search);

    const prefForm = useForm<{
        preferences: {
            category: string;
            in_app_enabled: boolean;
            whatsapp_enabled: boolean;
        }[];
    }>({
        preferences: preferences.map((pref) => ({
            category: pref.category,
            in_app_enabled: pref.in_app_enabled,
            whatsapp_enabled: pref.whatsapp_enabled,
        })),
    });

    function navigateFilters(next: Partial<typeof filters>) {
        router.get(
            notificationsIndex.url({
                query: {
                    search: next.search ?? searchText,
                    category: next.category ?? filters.category,
                    status: next.status ?? filters.status,
                    priority: next.priority ?? filters.priority,
                    tab: next.tab ?? activeTab,
                    activity_filter:
                        next.activity_filter ?? filters.activity_filter,
                },
            }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    }

    function handleSearchSubmit(event: FormEvent) {
        event.preventDefault();
        navigateFilters({ search: searchText, tab: 'inbox' });
    }

    function handleSavePreferences(event: FormEvent) {
        event.preventDefault();
        prefForm.put(updatePreferences.url(), {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Pusat Notifikasi & Aktivitas" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <header className="liquid-panel flex flex-col gap-4 rounded-[28px] p-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="section-kicker text-crimson">
                                AD Com Communication Hub
                            </p>
                            <h1 className="text-foreground mt-1 text-2xl font-black tracking-tight sm:text-3xl">
                                Pusat Notifikasi &amp; Aktivitas Operasional
                            </h1>
                            <p className="text-muted-foreground mt-1 max-w-2xl text-sm">
                                Pantau kotak masuk notifikasi terpadu,
                                peringatan prioritas, aktivitas operasional,
                                keandalan pengiriman WhatsApp, dan preferensi
                                kanal Anda.
                            </p>
                        </div>

                        {stats.unread > 0 && (
                            <Button
                                type="button"
                                onClick={() =>
                                    router.patch(
                                        markAllNotificationsRead.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className="rounded-xl font-bold"
                            >
                                <CheckCheck className="mr-2 size-4" />
                                Tandai semua dibaca ({stats.unread})
                            </Button>
                        )}
                    </header>

                    {/* Summary Metrics */}
                    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-muted-foreground text-xs font-bold uppercase">
                                Belum dibaca
                            </p>
                            <p className="text-crimson mt-2 text-3xl font-black">
                                {stats.unread}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-muted-foreground text-xs font-bold uppercase">
                                Prioritas tinggi &amp; kritis
                            </p>
                            <p className="mt-2 text-3xl font-black text-amber-600 dark:text-amber-400">
                                {stats.high_priority_unread}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-muted-foreground text-xs font-bold uppercase">
                                Notifikasi aktif
                            </p>
                            <p className="text-foreground mt-2 text-3xl font-black">
                                {stats.total_active}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-muted-foreground text-xs font-bold uppercase">
                                Diarsipkan
                            </p>
                            <p className="text-muted-foreground mt-2 text-3xl font-black">
                                {stats.archived}
                            </p>
                        </div>
                    </section>

                    {/* Priority Alerts Strip */}
                    {priorityAlerts.length > 0 && (
                        <section
                            aria-label="Peringatan prioritas"
                            className="liquid-panel rounded-[24px] border border-rose-500/25 bg-rose-500/10 p-5"
                        >
                            <div className="mb-3 flex items-center gap-2">
                                <ShieldAlert className="text-crimson size-4" />
                                <h2 className="text-foreground text-xs font-black tracking-wide uppercase">
                                    Peringatan Prioritas Aktif (
                                    {priorityAlerts.length})
                                </h2>
                            </div>
                            <div className="grid gap-3 md:grid-cols-2">
                                {priorityAlerts.map((alert) => (
                                    <div
                                        key={alert.key}
                                        className="surface-tile flex items-start justify-between gap-3 rounded-2xl border-rose-500/20 p-3.5"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-black uppercase ${
                                                        alert.severity ===
                                                        'critical'
                                                            ? 'bg-rose-600 text-white'
                                                            : 'status-pill-warning'
                                                    }`}
                                                >
                                                    {alert.category}
                                                </span>
                                            </div>
                                            <p className="text-foreground mt-1 text-sm font-bold">
                                                {alert.title}
                                            </p>
                                            <p className="text-muted-foreground mt-0.5 text-xs">
                                                {alert.description}
                                            </p>
                                        </div>
                                        {alert.href && (
                                            <Link
                                                href={alert.href}
                                                className="text-crimson inline-flex shrink-0 items-center gap-1 rounded-xl bg-rose-500/10 px-2.5 py-1.5 text-xs font-bold hover:bg-rose-500/20"
                                            >
                                                <span>Tinjau</span>
                                                <ArrowUpRight className="size-3.5" />
                                            </Link>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}

                    {/* Workspace Tabs */}
                    <nav
                        aria-label="Navigasi pusat notifikasi"
                        className="flex flex-wrap gap-2"
                    >
                        <button
                            type="button"
                            onClick={() => setActiveTab('inbox')}
                            className={`inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-xs font-bold transition ${
                                activeTab === 'inbox'
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'border-border bg-card text-muted-foreground hover:bg-muted border'
                            }`}
                        >
                            <Bell className="size-3.5" />
                            <span>Kotak Masuk</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('activity')}
                            className={`inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-xs font-bold transition ${
                                activeTab === 'activity'
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'border-border bg-card text-muted-foreground hover:bg-muted border'
                            }`}
                        >
                            <Activity className="size-3.5" />
                            <span>Aktivitas Operasional</span>
                        </button>
                        {deliveries.authorized && (
                            <button
                                type="button"
                                onClick={() => setActiveTab('deliveries')}
                                className={`inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-xs font-bold transition ${
                                    activeTab === 'deliveries'
                                        ? 'bg-primary text-primary-foreground shadow-sm'
                                        : 'border-border bg-card text-muted-foreground hover:bg-muted border'
                                }`}
                            >
                                <MessageSquareText className="size-3.5" />
                                <span>Pengiriman WhatsApp</span>
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => setActiveTab('preferences')}
                            className={`inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-xs font-bold transition ${
                                activeTab === 'preferences'
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'border-border bg-card text-muted-foreground hover:bg-muted border'
                            }`}
                        >
                            <SlidersHorizontal className="size-3.5" />
                            <span>Preferensi Kanal</span>
                        </button>
                    </nav>

                    {/* TAB 1: INBOX */}
                    {activeTab === 'inbox' && (
                        <section className="liquid-panel space-y-5 rounded-[26px] p-6">
                            <form
                                onSubmit={handleSearchSubmit}
                                className="grid gap-3 md:grid-cols-12"
                            >
                                <div className="relative md:col-span-5">
                                    <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                    <input
                                        type="text"
                                        value={searchText}
                                        onChange={(e) =>
                                            setSearchText(e.target.value)
                                        }
                                        placeholder="Cari judul, nomor tiket, atau isi notifikasi..."
                                        className="liquid-input w-full rounded-xl py-2 pr-3 pl-9 text-sm outline-none"
                                    />
                                </div>
                                <div className="md:col-span-3">
                                    <select
                                        aria-label="Filter kategori"
                                        value={filters.category}
                                        onChange={(e) =>
                                            navigateFilters({
                                                category: e.target.value,
                                            })
                                        }
                                        className="liquid-input w-full rounded-xl px-3 py-2 text-sm"
                                    >
                                        <option value="all">
                                            Semua kategori
                                        </option>
                                        {categoryOptions.map((opt) => (
                                            <option
                                                key={opt.value}
                                                value={opt.value}
                                            >
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="md:col-span-2">
                                    <select
                                        aria-label="Filter status baca"
                                        value={filters.status}
                                        onChange={(e) =>
                                            navigateFilters({
                                                status: e.target.value,
                                            })
                                        }
                                        className="liquid-input w-full rounded-xl px-3 py-2 text-sm"
                                    >
                                        <option value="all">Semua aktif</option>
                                        <option value="unread">
                                            Belum dibaca
                                        </option>
                                        <option value="read">
                                            Sudah dibaca
                                        </option>
                                        <option value="archived">
                                            Diarsipkan
                                        </option>
                                    </select>
                                </div>
                                <div className="flex gap-2 md:col-span-2">
                                    <select
                                        aria-label="Filter prioritas"
                                        value={filters.priority}
                                        onChange={(e) =>
                                            navigateFilters({
                                                priority: e.target.value,
                                            })
                                        }
                                        className="liquid-input w-full rounded-xl px-3 py-2 text-sm"
                                    >
                                        <option value="all">Prioritas</option>
                                        <option value="critical">Kritis</option>
                                        <option value="high">Tinggi</option>
                                        <option value="normal">Normal</option>
                                    </select>
                                    <Button type="submit" variant="secondary">
                                        Cari
                                    </Button>
                                </div>
                            </form>

                            {inbox.data.length === 0 ? (
                                <div className="border-border rounded-2xl border border-dashed py-14 text-center">
                                    <Bell className="text-muted-foreground/50 mx-auto size-8" />
                                    <p className="text-foreground mt-2 text-sm font-bold">
                                        Tidak ada notifikasi pada filter ini
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        Ubah kata kunci pencarian atau filter
                                        status untuk melihat riwayat lainnya.
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {inbox.data.map((item) => (
                                        <article
                                            key={item.id}
                                            className={`flex flex-col gap-3 rounded-2xl border p-4 transition sm:flex-row sm:items-start sm:justify-between ${
                                                item.is_read
                                                    ? 'border-border bg-card/70'
                                                    : 'bg-card border-rose-500/35 shadow-xs'
                                            }`}
                                        >
                                            <div className="min-w-0 space-y-1.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {!item.is_read && (
                                                        <span className="bg-crimson size-2 rounded-full" />
                                                    )}
                                                    <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-[10px] font-bold uppercase">
                                                        {item.category_label}
                                                    </span>
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${
                                                            priorityBadgeClass[
                                                                item.priority
                                                            ] ??
                                                            priorityBadgeClass.normal
                                                        }`}
                                                    >
                                                        {item.priority}
                                                    </span>
                                                    <span className="status-pill-success rounded-full px-2 py-0.5 text-[10px] font-semibold">
                                                        {item.channel_label}
                                                    </span>
                                                    <span className="text-muted-foreground text-[11px]">
                                                        {item.created_at} (
                                                        {
                                                            item.created_at_relative
                                                        }
                                                        )
                                                    </span>
                                                </div>

                                                <h3 className="text-foreground text-sm font-bold">
                                                    {item.title}
                                                </h3>
                                                <p className="text-muted-foreground text-xs leading-relaxed">
                                                    {item.body}
                                                </p>
                                            </div>

                                            <div className="flex shrink-0 flex-wrap items-center gap-1.5">
                                                {item.href &&
                                                    item.href_accessible && (
                                                        <Link
                                                            href={item.href}
                                                            className="text-crimson inline-flex items-center gap-1 rounded-xl bg-rose-500/10 px-3 py-1.5 text-xs font-bold hover:bg-rose-500/20"
                                                        >
                                                            <span>Buka</span>
                                                            <ArrowUpRight className="size-3.5" />
                                                        </Link>
                                                    )}
                                                {!item.is_read && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.patch(
                                                                markNotificationRead.url(
                                                                    {
                                                                        notificationId:
                                                                            item.id,
                                                                    },
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Check className="mr-1 size-3.5" />
                                                        Dibaca
                                                    </Button>
                                                )}
                                                {!item.is_archived && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        title="Arsipkan notifikasi"
                                                        onClick={() =>
                                                            router.patch(
                                                                archiveNotification.url(
                                                                    {
                                                                        notificationId:
                                                                            item.id,
                                                                    },
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Archive className="size-3.5" />
                                                    </Button>
                                                )}
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    title="Hapus notifikasi"
                                                    onClick={() =>
                                                        router.delete(
                                                            destroyNotification.url(
                                                                {
                                                                    notificationId:
                                                                        item.id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-3.5 text-rose-600" />
                                                </Button>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            )}

                            {inbox.links.length > 3 && (
                                <div className="flex flex-wrap items-center justify-end gap-1.5 pt-2">
                                    {inbox.links.map((link, idx) => (
                                        <Button
                                            key={idx}
                                            type="button"
                                            size="sm"
                                            disabled={!link.url}
                                            variant={
                                                link.active
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                link.url &&
                                                router.visit(link.url, {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            {link.label
                                                .replace('&laquo;', '«')
                                                .replace('&raquo;', '»')}
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </section>
                    )}

                    {/* TAB 2: ACTIVITY FEED */}
                    {activeTab === 'activity' && (
                        <section className="liquid-panel space-y-5 rounded-[26px] p-6">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 className="text-foreground text-base font-black">
                                        Umpan Aktivitas Operasional
                                    </h2>
                                    <p className="text-muted-foreground text-xs">
                                        Disusun dari catatan kejadian aktual
                                        sesuai hak akses peran Anda.
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-1.5">
                                    {activityFilterOptions.map((opt) => (
                                        <button
                                            key={opt}
                                            type="button"
                                            onClick={() =>
                                                navigateFilters({
                                                    tab: 'activity',
                                                    activity_filter: opt,
                                                })
                                            }
                                            className={`rounded-full px-3 py-1 text-xs font-bold transition ${
                                                filters.activity_filter === opt
                                                    ? 'bg-crimson text-white'
                                                    : 'border-border bg-card text-muted-foreground hover:bg-muted border'
                                            }`}
                                        >
                                            {activityFilterLabels[opt] ?? opt}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {activityFeed.length === 0 ? (
                                <p className="text-muted-foreground py-10 text-center text-sm">
                                    Belum ada aktivitas yang sesuai dengan
                                    filter saat ini.
                                </p>
                            ) : (
                                <div className="space-y-2.5">
                                    {activityFeed.map((entry) => (
                                        <div
                                            key={entry.id}
                                            className="surface-tile flex flex-col gap-2 rounded-2xl p-4 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0 space-y-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-black uppercase ${
                                                            entry.feed_type ===
                                                            'audit'
                                                                ? 'bg-primary text-primary-foreground'
                                                                : 'text-crimson bg-rose-500/10'
                                                        }`}
                                                    >
                                                        {entry.feed_type ===
                                                        'audit'
                                                            ? 'Audit Internal'
                                                            : entry.category_label}
                                                    </span>
                                                    <span className="text-muted-foreground text-[11px]">
                                                        {entry.actor} ·{' '}
                                                        {entry.occurred_at} (
                                                        {
                                                            entry.occurred_at_relative
                                                        }
                                                        )
                                                    </span>
                                                </div>
                                                <p className="text-foreground text-sm font-bold">
                                                    {entry.title}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {entry.description}
                                                </p>
                                            </div>
                                            {entry.href && (
                                                <Link
                                                    href={entry.href}
                                                    className="text-crimson inline-flex shrink-0 items-center gap-1 text-xs font-bold hover:underline"
                                                >
                                                    <span>Lihat</span>
                                                    <ArrowUpRight className="size-3.5" />
                                                </Link>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    )}

                    {/* TAB 3: WHATSAPP DELIVERY OBSERVABILITY */}
                    {activeTab === 'deliveries' && deliveries.authorized && (
                        <section className="liquid-panel space-y-5 rounded-[26px] p-6">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 className="text-foreground text-base font-black">
                                        Observabilitas Pengiriman WhatsApp
                                    </h2>
                                    <p className="text-muted-foreground text-xs">
                                        Status pengiriman pesan WhatsApp dicatat
                                        terpisah dari notifikasi In-App. Token
                                        API dan data kredensial disamarkan.
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2 text-xs">
                                    <span className="surface-tile rounded-xl px-3 py-1.5 font-semibold">
                                        Queue: {deliveries.queue_connection}
                                    </span>
                                    <span className="surface-tile rounded-xl px-3 py-1.5 font-semibold">
                                        Pending: {deliveries.pending_jobs}
                                    </span>
                                    <span className="surface-tile rounded-xl px-3 py-1.5 font-semibold">
                                        Failed jobs: {deliveries.failed_jobs}
                                    </span>
                                </div>
                            </div>

                            {!fonnteConfigured && (
                                <div className="flex items-start gap-2 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-3.5 text-xs text-amber-800 dark:text-amber-200">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    <p>
                                        Gateway WhatsApp (Fonnte) belum
                                        dikonfigurasi. Notifikasi tetap
                                        tersimpan di kotak masuk In-App
                                        aplikasi, namun pengiriman WhatsApp
                                        berstatus <strong>skipped</strong>.
                                    </p>
                                </div>
                            )}

                            {deliveries.items.length === 0 ? (
                                <p className="text-muted-foreground py-10 text-center text-sm">
                                    Belum ada catatan antrean pengiriman
                                    WhatsApp.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead>
                                            <tr className="text-muted-foreground border-border border-b uppercase">
                                                <th className="py-2 pr-3">
                                                    Event
                                                </th>
                                                <th className="py-2 pr-3">
                                                    Penerima
                                                </th>
                                                <th className="py-2 pr-3">
                                                    Status WA
                                                </th>
                                                <th className="py-2 pr-3">
                                                    Percobaan
                                                </th>
                                                <th className="py-2 pr-3">
                                                    Catatan / Error
                                                </th>
                                                <th className="py-2 text-right">
                                                    Aksi
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {deliveries.items.map((row) => (
                                                <tr key={row.id}>
                                                    <td className="py-3 pr-3">
                                                        <p className="text-foreground font-bold">
                                                            {row.title}
                                                        </p>
                                                        <p className="text-muted-foreground font-mono text-[11px]">
                                                            {row.event_key}
                                                        </p>
                                                    </td>
                                                    <td className="py-3 pr-3">
                                                        <p className="text-foreground font-medium">
                                                            {row.recipient_name}
                                                        </p>
                                                        <p className="text-muted-foreground">
                                                            {row.target}
                                                        </p>
                                                    </td>
                                                    <td className="py-3 pr-3">
                                                        <span
                                                            className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-black uppercase ${
                                                                deliveryStatusBadge[
                                                                    row.status
                                                                ] ??
                                                                'border-border bg-muted text-muted-foreground border'
                                                            }`}
                                                        >
                                                            {row.status}
                                                        </span>
                                                        {row.sent_at && (
                                                            <p className="text-muted-foreground mt-0.5 text-[10px]">
                                                                Terkirim:{' '}
                                                                {row.sent_at}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="py-3 pr-3">
                                                        {row.attempts}x
                                                        {row.retried_at && (
                                                            <p className="text-muted-foreground text-[10px]">
                                                                Ulang:{' '}
                                                                {row.retried_at}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground max-w-xs py-3 pr-3">
                                                        {row.last_error || '—'}
                                                    </td>
                                                    <td className="py-3 text-right">
                                                        {row.can_retry && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    router.post(
                                                                        retryDelivery.url(
                                                                            row.id,
                                                                        ),
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <RefreshCw className="mr-1 size-3" />
                                                                Kirim ulang
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    )}

                    {/* TAB 4: PREFERENCES */}
                    {activeTab === 'preferences' && (
                        <section className="liquid-panel space-y-5 rounded-[26px] p-6">
                            <div>
                                <h2 className="text-foreground text-base font-black">
                                    Preferensi Notifikasi Pribadi
                                </h2>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Atur kanal penerimaan notifikasi sesuai
                                    kebutuhan. Kategori keamanan dan kebijakan
                                    wajib tidak dapat dinonaktifkan.
                                </p>
                            </div>

                            <form
                                onSubmit={handleSavePreferences}
                                className="space-y-3"
                            >
                                {preferences.map((pref, index) => {
                                    const current =
                                        prefForm.data.preferences[index];

                                    return (
                                        <div
                                            key={pref.category}
                                            className="surface-tile flex flex-col gap-3 rounded-2xl p-4 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <p className="text-foreground text-sm font-bold">
                                                        {pref.label}
                                                    </p>
                                                    {pref.mandatory && (
                                                        <span className="status-pill-danger inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold">
                                                            <Lock className="size-3" />
                                                            Wajib · Terkunci
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-muted-foreground mt-0.5 text-xs">
                                                    {pref.description}
                                                </p>
                                            </div>

                                            <div className="flex shrink-0 items-center gap-4 text-xs font-semibold">
                                                <label className="inline-flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        disabled={
                                                            pref.mandatory
                                                        }
                                                        checked={
                                                            current?.in_app_enabled ??
                                                            true
                                                        }
                                                        onChange={(e) => {
                                                            const next = [
                                                                ...prefForm.data
                                                                    .preferences,
                                                            ];
                                                            next[index] = {
                                                                ...next[index],
                                                                in_app_enabled:
                                                                    e.target
                                                                        .checked,
                                                            };
                                                            prefForm.setData(
                                                                'preferences',
                                                                next,
                                                            );
                                                        }}
                                                        className="size-4 rounded"
                                                    />
                                                    <span>In-App</span>
                                                </label>
                                                <label className="inline-flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        disabled={
                                                            pref.mandatory
                                                        }
                                                        checked={
                                                            current?.whatsapp_enabled ??
                                                            true
                                                        }
                                                        onChange={(e) => {
                                                            const next = [
                                                                ...prefForm.data
                                                                    .preferences,
                                                            ];
                                                            next[index] = {
                                                                ...next[index],
                                                                whatsapp_enabled:
                                                                    e.target
                                                                        .checked,
                                                            };
                                                            prefForm.setData(
                                                                'preferences',
                                                                next,
                                                            );
                                                        }}
                                                        className="size-4 rounded"
                                                    />
                                                    <span>WhatsApp</span>
                                                </label>
                                            </div>
                                        </div>
                                    );
                                })}

                                <div className="flex justify-end pt-2">
                                    <Button
                                        type="submit"
                                        disabled={prefForm.processing}
                                    >
                                        Simpan preferensi
                                    </Button>
                                </div>
                            </form>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

NotificationsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Pusat Notifikasi', href: '#' },
    ],
};

import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    BellRing,
    Bot,
    KeyRound,
    MessageSquareText,
    RefreshCw,
    Send,
    ShieldCheck,
} from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as aiProvidersIndex } from '@/routes/ai-providers';
import {
    test as testFonnte,
    sendTest as sendFonnteTest,
    update as updateFonnte,
} from '@/routes/integrations-settings/fonnte';
import { update as updateNotificationConfig } from '@/routes/integrations-settings/notifications';
import { retry as retryDelivery } from '@/routes/notifications/deliveries';

type NotificationEvent = {
    key: string;
    label: string;
    description: string;
    enabled: boolean;
    template: string;
};

type DeliveryRow = {
    id: number;
    event_key: string;
    channel: string;
    target: string;
    status: string;
    attempts: number;
    last_error: string | null;
    created_at: string | null;
};

type Props = {
    fonnte: {
        configured: boolean;
        masked_hint: string | null;
        last_status: string;
        last_tested_at: string | null;
        last_error: string | null;
    };
    aiProviderSummary: {
        configured: boolean;
        name: string;
        driver_label: string;
        model_id: string | null;
        connection_status: string;
        last_validated_at: string | null;
        last_error: string | null;
    } | null;
    worker: {
        queue_connection: string;
        pending_jobs: number;
        failed_jobs: number;
        recent_deliveries: DeliveryRow[];
    };
    notificationEvents: NotificationEvent[];
};

export default function IntegrationsSettingsPage({
    fonnte,
    aiProviderSummary,
    worker,
    notificationEvents,
}: Props) {
    const fonnteForm = useForm({ token: '' });
    const fonnteTestMsgForm = useForm({
        phone: '',
        message: '[AD Com] Pesan uji koneksi WhatsApp Fonnte berhasil dikirim.',
    });
    const notifForm = useForm({
        events: notificationEvents.map((event) => ({
            key: event.key,
            enabled: event.enabled,
            template: event.template,
        })),
    });

    function submitFonnte(event: FormEvent) {
        event.preventDefault();
        fonnteForm.put(updateFonnte.url(), {
            preserveScroll: true,
            onSuccess: () => fonnteForm.reset('token'),
        });
    }

    function submitFonnteTestMsg(event: FormEvent) {
        event.preventDefault();
        fonnteTestMsgForm.post(sendFonnteTest.url(), {
            preserveScroll: true,
        });
    }

    function submitNotificationConfig(event: FormEvent) {
        event.preventDefault();
        notifForm.put(updateNotificationConfig.url(), {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Integrasi & Notifikasi" />
            <div className="space-y-6">
                <header>
                    <p className="section-kicker text-cyan">
                        Pusat Integrasi Superadmin
                    </p>
                    <h1 className="text-foreground mt-2 text-2xl font-black">
                        Integrasi Eksternal, AI & Engine Notifikasi
                    </h1>
                    <p className="text-muted-foreground mt-2 max-w-2xl text-sm leading-6">
                        Kelola token terenkripsi Fonnte & OpenAI, uji koneksi
                        langsung dari backend, atur template notifikasi, dan
                        pantau status antrean worker.
                    </p>
                </header>

                {/* A. WhatsApp Integration - Fonnte */}
                <section className="liquid-panel rounded-[26px] p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <span className="bg-cyan/12 text-cyan grid size-10 shrink-0 place-items-center rounded-xl">
                                <MessageSquareText className="size-5" />
                            </span>
                            <div>
                                <h2 className="text-foreground text-lg font-black">
                                    WhatsApp Integration — Fonnte
                                </h2>
                                <p className="text-muted-foreground text-xs">
                                    Status:{' '}
                                    <span className="font-bold">
                                        {fonnte.configured
                                            ? `Configured (${fonnte.masked_hint})`
                                            : 'Not configured'}
                                    </span>{' '}
                                    · Koneksi terakhir: {fonnte.last_status}
                                    {fonnte.last_tested_at
                                        ? ` (${fonnte.last_tested_at})`
                                        : ''}
                                </p>
                                {fonnte.last_error && (
                                    <p className="text-crimson mt-1 text-xs font-semibold">
                                        {fonnte.last_error}
                                    </p>
                                )}
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    testFonnte.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Activity className="size-4" /> Test Connection
                        </Button>
                    </div>

                    <div className="mt-5 grid gap-5 lg:grid-cols-2">
                        <form onSubmit={submitFonnte} className="space-y-3">
                            <div className="grid gap-2">
                                <Label htmlFor="fonnte_token">
                                    API Token Fonnte (disimpan terenkripsi)
                                </Label>
                                <Input
                                    id="fonnte_token"
                                    type="password"
                                    placeholder={
                                        fonnte.configured
                                            ? 'Masukkan token baru untuk memperbarui'
                                            : 'Masukkan API token Fonnte'
                                    }
                                    value={fonnteForm.data.token}
                                    onChange={(e) =>
                                        fonnteForm.setData(
                                            'token',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={fonnteForm.errors.token} />
                            </div>
                            <Button disabled={fonnteForm.processing}>
                                <KeyRound className="size-4" />
                                {fonnteForm.processing
                                    ? 'Menyimpan…'
                                    : 'Simpan Token Terenkripsi'}
                            </Button>
                            {fonnteForm.recentlySuccessful && (
                                <p className="text-xs font-bold text-emerald-700">
                                    Token tersimpan aman (encrypted).
                                </p>
                            )}
                        </form>

                        <form
                            onSubmit={submitFonnteTestMsg}
                            className="space-y-3"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="fonnte_test_phone">
                                    Send Test Message (hanya ke nomor uji ini)
                                </Label>
                                <Input
                                    id="fonnte_test_phone"
                                    placeholder="Contoh: 6281234567890"
                                    value={fonnteTestMsgForm.data.phone}
                                    onChange={(e) =>
                                        fonnteTestMsgForm.setData(
                                            'phone',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={fonnteTestMsgForm.errors.phone}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fonnte_test_message">
                                    Isi pesan uji
                                </Label>
                                <Input
                                    id="fonnte_test_message"
                                    value={fonnteTestMsgForm.data.message}
                                    onChange={(e) =>
                                        fonnteTestMsgForm.setData(
                                            'message',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <Button
                                variant="secondary"
                                disabled={fonnteTestMsgForm.processing}
                            >
                                <Send className="size-4" /> Send Test Message
                            </Button>
                        </form>
                    </div>
                </section>

                {/* B. AI Provider Status (Single Source of Truth) */}
                <section className="liquid-panel rounded-[26px] p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <span className="bg-brand/12 text-brand grid size-10 shrink-0 place-items-center rounded-xl">
                                <Bot className="size-5" />
                            </span>
                            <div>
                                <h2 className="text-foreground text-lg font-black">
                                    AI Integration — ADI (Single Source of
                                    Truth)
                                </h2>
                                <p className="text-muted-foreground mt-1 text-xs leading-5">
                                    Seluruh API token, driver provider, uji
                                    koneksi, dan model aktif ADI kini dikelola
                                    terpusat di{' '}
                                    <strong>AI Provider Manager</strong> agar
                                    tidak terjadi duplikasi konfigurasi.
                                </p>
                                <p className="text-foreground/85 mt-2 text-xs font-semibold">
                                    {aiProviderSummary
                                        ? `${aiProviderSummary.name} (${aiProviderSummary.driver_label}) · Model: ${aiProviderSummary.model_id ?? 'Belum dipilih'} · Koneksi: ${aiProviderSummary.connection_status}`
                                        : 'Belum ada AI Provider default aktif (ADI berjalan dalam mode panduan internal).'}
                                </p>
                                {aiProviderSummary?.last_error && (
                                    <p className="text-crimson mt-1 text-xs font-semibold">
                                        {aiProviderSummary.last_error}
                                    </p>
                                )}
                            </div>
                        </div>
                        <Button asChild variant="outline">
                            <Link href={aiProvidersIndex()}>
                                Buka AI Provider Manager
                            </Link>
                        </Button>
                    </div>
                </section>

                {/* C. Notification Templates & Event Toggles */}
                <section className="liquid-panel rounded-[26px] p-6">
                    <div className="flex items-start gap-3">
                        <span className="bg-cyan/12 text-cyan grid size-10 shrink-0 place-items-center rounded-xl">
                            <BellRing className="size-5" />
                        </span>
                        <div>
                            <h2 className="text-foreground text-lg font-black">
                                Konfigurasi Event & Template Notifikasi
                            </h2>
                            <p className="text-muted-foreground text-xs">
                                Notifikasi in-app tetap aktif dan tersimpan di
                                database walaupun Fonnte belum dikonfigurasi.
                                Placeholder tersedia: {'{title}'}, {'{body}'}.
                            </p>
                        </div>
                    </div>

                    <form
                        onSubmit={submitNotificationConfig}
                        className="mt-5 space-y-3"
                    >
                        <div className="grid gap-3 md:grid-cols-2">
                            {notificationEvents.map((meta, index) => {
                                const current = notifForm.data.events[index];

                                return (
                                    <div
                                        key={meta.key}
                                        className="liquid-control rounded-2xl p-4"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-foreground text-sm font-black">
                                                {meta.label}
                                            </p>
                                            <label className="flex items-center gap-1.5 text-xs font-bold">
                                                <input
                                                    type="checkbox"
                                                    checked={current.enabled}
                                                    onChange={(e) => {
                                                        const next = [
                                                            ...notifForm.data
                                                                .events,
                                                        ];
                                                        next[index] = {
                                                            ...current,
                                                            enabled:
                                                                e.target
                                                                    .checked,
                                                        };
                                                        notifForm.setData(
                                                            'events',
                                                            next,
                                                        );
                                                    }}
                                                    className="accent-cyan size-4"
                                                />
                                                Aktif
                                            </label>
                                        </div>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {meta.description}
                                        </p>
                                        <Input
                                            className="mt-2"
                                            value={current.template}
                                            onChange={(e) => {
                                                const next = [
                                                    ...notifForm.data.events,
                                                ];
                                                next[index] = {
                                                    ...current,
                                                    template: e.target.value,
                                                };
                                                notifForm.setData(
                                                    'events',
                                                    next,
                                                );
                                            }}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                        <Button disabled={notifForm.processing}>
                            <ShieldCheck className="size-4" /> Simpan Template
                            Notifikasi
                        </Button>
                    </form>
                </section>

                {/* D. Worker & Delivery Status */}
                <section className="liquid-panel rounded-[26px] p-6">
                    <h2 className="text-foreground text-lg font-black">
                        Status Antrean & Log Pengiriman WhatsApp
                    </h2>
                    <p className="text-muted-foreground mt-1 text-xs">
                        Driver antrean:{' '}
                        <strong>{worker.queue_connection}</strong> · Antrean
                        tertunda: <strong>{worker.pending_jobs}</strong> · Job
                        gagal: <strong>{worker.failed_jobs}</strong>. Status
                        worker latar belakang tidak dapat diverifikasi dari
                        request web; pastikan menjalankan{' '}
                        <code>php artisan queue:work</code> di server produksi.
                    </p>

                    <div className="mt-4 space-y-2">
                        {worker.recent_deliveries.length === 0 ? (
                            <p className="text-muted-foreground text-xs">
                                Belum ada catatan pengiriman WhatsApp.
                            </p>
                        ) : (
                            worker.recent_deliveries.map((row) => (
                                <div
                                    key={row.id}
                                    className="surface-tile flex flex-wrap items-center justify-between gap-2 rounded-2xl px-4 py-3 text-xs"
                                >
                                    <div>
                                        <p className="text-foreground font-bold">
                                            {row.event_key} · {row.target}
                                        </p>
                                        <p className="text-muted-foreground">
                                            Status: {row.status} · Percobaan:{' '}
                                            {row.attempts}
                                            {row.last_error
                                                ? ` · ${row.last_error}`
                                                : ''}
                                        </p>
                                    </div>
                                    {(row.status === 'failed' ||
                                        row.status === 'skipped') && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    retryDelivery.url({
                                                        delivery: row.id,
                                                    }),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <RefreshCw className="size-3.5" />{' '}
                                            Kirim ulang
                                        </Button>
                                    )}
                                </div>
                            ))
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    Banknote,
    Boxes,
    Bot,
    ClipboardList,
    KeyRound,
    Settings2,
    ShieldAlert,
    UsersRound,
    Wrench,
} from 'lucide-react';
import { MotionNumber } from '@/components/motion/MotionNumber';
import {
    MotionStagger,
    MotionStaggerItem,
} from '@/components/motion/MotionStagger';
import { controlCenter as adminControlCenter } from '@/routes/admin';
import { index as aiProvidersIndex } from '@/routes/ai-providers';
import { index as financeIndex } from '@/routes/finance';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as servicesSettingsIndex } from '@/routes/services-settings';
import { edit as integrationsSettingsEdit } from '@/routes/integrations-settings';
import { edit as systemSettingsEdit } from '@/routes/system-settings';
import { index as teamIndex } from '@/routes/team';
import { performance } from '@/routes/technicians';
import { edit as assistantSettings } from '@/routes/assistant-settings';
import { index as ordersIndex } from '@/routes/orders';

type Props = {
    isSuperadmin: boolean;
    auditEvent: string;
    servicesCount: number;
    roleCounts: Record<string, number>;
    signals: {
        open_orders: number;
        unassigned_orders: number;
        pending_transactions: number;
        low_stock_items: number;
        inactive_accounts: number;
        accounts_missing_phone: number;
    };
    recentActivity: Array<{
        event: string;
        target: string;
        actor: string;
        created_at: string;
    }>;
    assistant: { provider: string; model: string; key_configured: boolean };
    whatsapp: { gateway_configured: boolean };
};

const modules = [
    {
        title: 'Tim dan akun',
        detail: 'Role, status aktif, kontak, dan tim layanan.',
        href: teamIndex(),
        icon: UsersRound,
    },
    {
        title: 'Pesanan layanan',
        detail: 'Tiket aktif, penugasan, status, dan percakapan.',
        href: ordersIndex(),
        icon: ClipboardList,
    },
    {
        title: 'Keuangan',
        detail: 'Catatan transaksi dan verifikasi pembayaran.',
        href: financeIndex(),
        icon: Banknote,
    },
    {
        title: 'Inventaris',
        detail: 'Barang, stok minimum, dan mutasi persediaan.',
        href: inventoryIndex(),
        icon: Boxes,
    },
    {
        title: 'Performa teknisi',
        detail: 'Rating terverifikasi dan tindak lanjut mutu.',
        href: performance(),
        icon: Activity,
    },
    {
        title: 'Asisten ADI',
        detail: 'Model, API key server, dan perilaku asisten.',
        href: assistantSettings(),
        icon: Bot,
    },
];

export default function AdminControlCenter({
    isSuperadmin,
    auditEvent,
    servicesCount,
    roleCounts,
    signals,
    recentActivity,
    assistant,
    whatsapp,
}: Props) {
    const visibleModules = [
        ...modules,
        ...(isSuperadmin
            ? [
                  {
                      title: 'Layanan & katalog',
                      detail: `${servicesCount} layanan aktif di katalog AD Com.`,
                      href: servicesSettingsIndex(),
                      icon: Wrench,
                  },
                  {
                      title: 'Provider & Model AI',
                      detail: 'Multi-provider AI, token terenkripsi, dan model discovery dinamis.',
                      href: aiProvidersIndex(),
                      icon: Bot,
                  },
                  {
                      title: 'Integrasi & notifikasi',
                      detail: 'Token Fonnte, OpenAI, uji koneksi, dan worker antrean.',
                      href: integrationsSettingsEdit(),
                      icon: KeyRound,
                  },
                  {
                      title: 'Konfigurasi sistem',
                      detail: 'Identitas platform, jam operasional, dan garansi default.',
                      href: systemSettingsEdit(),
                      icon: Settings2,
                  },
              ]
            : []),
    ];
    const cards = [
        [
            'Pesanan aktif',
            signals.open_orders,
            ordersIndex({ query: { group: 'active' } }),
        ],
        [
            'Belum ditugaskan',
            signals.unassigned_orders,
            ordersIndex({ query: { group: 'unassigned' } }),
        ],
        ['Transaksi pending', signals.pending_transactions, financeIndex()],
        ['Stok perlu restock', signals.low_stock_items, inventoryIndex()],
        [
            'Akun nonaktif',
            signals.inactive_accounts,
            teamIndex({ query: { filter: 'inactive' } }),
        ],
        [
            'Akun tanpa WhatsApp',
            signals.accounts_missing_phone,
            teamIndex({ query: { filter: 'missing_phone' } }),
        ],
    ] as const;

    return (
        <>
            <Head
                title={
                    isSuperadmin
                        ? 'Pusat Kendali Superadmin'
                        : 'Pusat kendali admin'
                }
            />
            <div className="space-y-7">
                <header>
                    <p className="section-kicker text-cyan">
                        {isSuperadmin
                            ? 'Kontrol Superadmin AD Com'
                            : 'Administrasi AD Com'}
                    </p>
                    <h1 className="text-foreground mt-2 text-2xl font-black">
                        {isSuperadmin
                            ? 'Pusat Kendali Superadmin'
                            : 'Pusat kendali admin'}
                    </h1>
                    <p className="text-muted-foreground mt-2 max-w-2xl text-sm leading-6">
                        Pantau kondisi operasional dan masuk langsung ke area
                        yang perlu dikelola. Setiap menu tetap mengikuti hak
                        akses di server.
                    </p>
                </header>

                <section aria-label="Sinyal operasional">
                    <MotionStagger className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {cards.map(([label, value, href]) => (
                            <MotionStaggerItem key={label}>
                                <Link
                                    href={href}
                                    className="liquid-panel hover:border-brand/40 block rounded-2xl p-4 transition-all duration-200 hover:-translate-y-0.5"
                                >
                                    <p className="text-muted-foreground text-xs">
                                        {label}
                                    </p>
                                    <p className="text-foreground mt-2 text-2xl font-black">
                                        <MotionNumber value={value} />
                                    </p>
                                </Link>
                            </MotionStaggerItem>
                        ))}
                    </MotionStagger>
                </section>

                <section>
                    <h2 className="text-foreground text-lg font-black">
                        Area pengelolaan
                    </h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        {visibleModules.map(
                            ({ title, detail, href, icon: Icon }) => (
                                <Link
                                    key={title}
                                    href={href}
                                    className="liquid-panel group rounded-2xl p-4 transition-transform hover:-translate-y-0.5"
                                >
                                    <div className="flex items-start gap-3">
                                        <span className="bg-cyan/10 text-cyan grid size-10 shrink-0 place-items-center rounded-xl">
                                            <Icon className="size-5" />
                                        </span>
                                        <span>
                                            <span className="text-foreground block font-bold">
                                                {title}
                                            </span>
                                            <span className="text-muted-foreground mt-1 block text-xs leading-5">
                                                {detail}
                                            </span>
                                        </span>
                                    </div>
                                </Link>
                            ),
                        )}
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-3">
                    <div className="liquid-panel rounded-2xl p-5">
                        <h2 className="text-foreground font-black">
                            Akun menurut role
                        </h2>
                        <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
                            {Object.entries(roleCounts).map(([role, total]) => (
                                <div
                                    key={role}
                                    className="surface-tile flex justify-between rounded-xl px-3 py-2"
                                >
                                    <dt className="text-muted-foreground capitalize">
                                        {role}
                                    </dt>
                                    <dd className="text-foreground font-bold">
                                        {total}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                    <div className="liquid-panel rounded-2xl p-5">
                        <h2 className="text-foreground font-black">
                            OTP pemulihan password
                        </h2>
                        <p className="text-muted-foreground mt-3 text-sm">
                            Gateway: Fonnte
                        </p>
                        <p className="mt-2 text-sm">
                            API token:{' '}
                            {whatsapp.gateway_configured
                                ? 'Tersimpan di server'
                                : 'Belum dikonfigurasi'}
                        </p>
                        <p className="text-muted-foreground/80 mt-2 text-xs">
                            Kode WhatsApp hanya untuk ganti atau reset password.
                            Token dibaca dari konfigurasi server.
                        </p>
                    </div>
                    <div className="liquid-panel rounded-2xl p-5">
                        <h2 className="text-foreground flex items-center gap-2 font-black">
                            <Bot className="size-4" /> Koneksi ADI
                        </h2>
                        <p className="text-muted-foreground mt-3 text-sm">
                            Provider: {assistant.provider}
                        </p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Model: {assistant.model}
                        </p>
                        <p className="mt-2 text-sm">
                            API key:{' '}
                            {assistant.key_configured
                                ? 'Tersimpan di server'
                                : 'Belum dikonfigurasi'}
                        </p>
                        <p className="text-muted-foreground/80 mt-2 flex items-start gap-1.5 text-xs">
                            <ShieldAlert className="mt-0.5 size-3.5 shrink-0" />{' '}
                            Key tidak ditampilkan di halaman ini.
                        </p>
                    </div>
                </section>

                <section className="liquid-panel rounded-2xl p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-foreground flex items-center gap-2 font-black">
                            <Activity className="size-4" /> Aktivitas
                            pengelolaan terbaru
                        </h2>
                        <div className="flex flex-wrap gap-1.5 text-xs">
                            {[
                                ['', 'Semua'],
                                ['team.', 'Tim & Role'],
                                ['service.', 'Layanan'],
                                ['system.', 'Sistem'],
                                ['service_order.', 'Tiket'],
                            ].map(([value, label]) => (
                                <Link
                                    key={label}
                                    href={adminControlCenter({
                                        query: value
                                            ? { audit_event: value }
                                            : undefined,
                                    })}
                                    preserveScroll
                                    className={`rounded-full px-3 py-1 font-bold transition-colors ${
                                        auditEvent === value
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {label}
                                </Link>
                            ))}
                        </div>
                    </div>
                    {recentActivity.length ? (
                        <ul className="divide-border mt-3 divide-y">
                            {recentActivity.map((event, index) => (
                                <li
                                    key={`${event.event}-${index}`}
                                    className="flex flex-wrap justify-between gap-2 py-3 text-sm"
                                >
                                    <span className="text-foreground">
                                        {event.event}{' '}
                                        <span className="text-muted-foreground">
                                            · {event.target} · {event.actor}
                                        </span>
                                    </span>
                                    <time className="text-muted-foreground text-xs">
                                        {event.created_at}
                                    </time>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground mt-3 flex items-start gap-2 text-sm">
                            <Activity className="mt-0.5 size-4 shrink-0" />{' '}
                            Belum ada aktivitas yang tercatat.
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}

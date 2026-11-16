import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarDays,
    Check,
    ClipboardCheck,
    Clock3,
    Copy,
    MapPin,
    Phone,
    ReceiptText,
    ShieldCheck,
    Star,
    UserRound,
    WalletCards,
} from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import AdComAssistant from '@/components/adcom-assistant';
import { EASE } from '@/lib/motion';
import PublicShell from '@/components/public-shell';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { index as trackIndex } from '@/routes/track';

type TimelineItem = {
    status: string;
    title: string;
    description: string | null;
    occurred_at: string;
};
type EstimateItem = {
    label: string;
    quantity: number;
    unit_price: number;
    total: number;
};
type Technician = {
    name: string;
    phone: string | null;
    avatar_url: string | null;
    specialty: string | null;
    bio: string | null;
    rating_average: number | null;
    reviews_count: number;
};
type TechnicianSummary = Pick<
    Technician,
    'name' | 'avatar_url' | 'specialty' | 'rating_average' | 'reviews_count'
>;
type Order = {
    ticket_number: string;
    status: string;
    status_label: string;
    customer_name: string;
    service: string;
    service_code: string;
    complaint: string;
    preferred_date: string | null;
    preferred_window: string | null;
    scheduled_at: string | null;
    technician: Technician | null;
    technician_team: TechnicianSummary[];
    supervisor: string | null;
    risk_level: string;
    estimated_total: number | null;
    created_at: string;
    timeline: TimelineItem[];
    estimate: {
        version: number;
        status: string;
        total: number;
        valid_until: string | null;
        items: EstimateItem[];
    } | null;
    payment: {
        verified: number;
        pending: number;
        last_method: string | null;
        last_reference: string | null;
    };
};

const money = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const initials = (name: string) =>
    name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

export default function TrackShow({
    order,
    created,
    accessCode,
}: {
    order: Order;
    created: boolean;
    accessCode?: string;
}) {
    const [copied, setCopied] = useState(false);

    async function copyCredentials() {
        if (!accessCode) return;
        await navigator.clipboard.writeText(
            `${order.ticket_number} · ${accessCode}`,
        );
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1800);
    }

    return (
        <PublicShell backHref={trackIndex.url()} backLabel="Lacak tiket lain">
            <Head title={`${order.ticket_number} · ${order.status_label}`} />
            <main className="bg-background min-h-[calc(100vh-9rem)] py-9 sm:py-12">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    {created && accessCode && (
                        <section className="liquid-panel border-primary/30 bg-primary/8 text-foreground mb-6 overflow-hidden rounded-[24px] border">
                            <div className="grid gap-5 p-5 sm:grid-cols-[1fr_auto] sm:items-center sm:p-7">
                                <div>
                                    <p className="section-kicker">
                                        Tiket berhasil dibuat
                                    </p>
                                    <h1 className="mt-2 text-2xl font-semibold tracking-[-0.04em]">
                                        Simpan kode ini sekarang.
                                    </h1>
                                    <p className="text-muted-foreground mt-2 text-sm leading-6">
                                        Kode akses hanya ditampilkan sekali. Tim
                                        AD Com juga dapat mengirimkannya melalui
                                        kontak yang kamu cantumkan.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={copyCredentials}
                                    className="liquid-panel-dark flex min-w-64 items-center justify-between gap-5 rounded-2xl px-5 py-4 text-left text-white"
                                >
                                    <span>
                                        <span className="block font-mono text-xs text-white/48">
                                            {order.ticket_number}
                                        </span>
                                        <span className="text-lime mt-1 block font-mono text-xl font-semibold tracking-[0.18em]">
                                            {accessCode}
                                        </span>
                                    </span>
                                    {copied ? (
                                        <Check className="text-lime" />
                                    ) : (
                                        <Copy className="text-cyan" />
                                    )}
                                </button>
                            </div>
                        </section>
                    )}

                    <section className="liquid-panel-dark rounded-2xl p-6 text-white sm:p-8">
                        <div className="flex flex-col justify-between gap-7 md:flex-row md:items-end">
                            <div>
                                <p className="text-cyan font-mono text-xs font-bold tracking-[0.16em]">
                                    {order.ticket_number} · {order.service_code}
                                </p>
                                <h1 className="mt-3 text-3xl font-semibold tracking-[-0.045em] sm:text-4xl">
                                    {order.service}
                                </h1>
                                <p className="mt-3 max-w-2xl text-sm leading-6 text-white/52">
                                    {order.complaint}
                                </p>
                            </div>
                            <div className="border-lime/35 bg-lime/10 shrink-0 rounded-2xl border px-5 py-4">
                                <p className="text-lime text-[10px] font-semibold tracking-wider uppercase">
                                    Status sekarang
                                </p>
                                <p className="mt-1 font-semibold">
                                    {order.status_label}
                                </p>
                            </div>
                        </div>
                    </section>

                    <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(290px,.75fr)] lg:items-start">
                        <section className="liquid-panel rounded-[26px] p-5 sm:p-7">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        Linimasa layanan
                                    </p>
                                    <h2 className="mt-2 text-2xl font-semibold tracking-[-0.035em]">
                                        Yang sudah terjadi
                                    </h2>
                                </div>
                                <ClipboardCheck className="text-cyan size-7" />
                            </div>
                            <ol className="mt-7">
                                {order.timeline.map((event, index) => (
                                    <motion.li
                                        key={`${event.status}-${event.occurred_at}`}
                                        initial={{ opacity: 0, y: 8 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{
                                            duration: 0.3,
                                            delay: index * 0.05,
                                            ease: EASE.apple,
                                        }}
                                        className="relative grid grid-cols-[30px_1fr] gap-3 pb-7 last:pb-0"
                                    >
                                        {index < order.timeline.length - 1 && (
                                            <span className="bg-ink/12 absolute top-7 bottom-0 left-[14px] w-px" />
                                        )}
                                        <span
                                            className={`border-background relative z-10 mt-0.5 grid size-[30px] place-items-center rounded-full border-4 ${index === order.timeline.length - 1 ? 'bg-cyan text-white shadow-[0_0_0_4px_rgba(169,37,59,0.14)]' : 'bg-lime text-slate-950'}`}
                                        >
                                            <Check className="size-3.5" />
                                        </span>
                                        <div>
                                            <p className="font-semibold">
                                                {event.title}
                                            </p>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {event.occurred_at}
                                            </p>
                                            {event.description && (
                                                <p className="text-muted-foreground mt-2 text-sm leading-6">
                                                    {event.description}
                                                </p>
                                            )}
                                        </div>
                                    </motion.li>
                                ))}
                            </ol>
                        </section>

                        <aside className="space-y-5">
                            <section className="liquid-panel rounded-[26px] p-5">
                                <p className="section-kicker text-cyan">
                                    Detail kunjungan
                                </p>
                                <dl className="mt-5 space-y-4 text-sm">
                                    <Detail
                                        icon={CalendarDays}
                                        label="Waktu pilihan"
                                        value={
                                            [
                                                order.preferred_date,
                                                order.preferred_window,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') ||
                                            'Belum ditentukan'
                                        }
                                    />
                                    <Detail
                                        icon={Clock3}
                                        label="Jadwal terkonfirmasi"
                                        value={
                                            order.scheduled_at ||
                                            'Belum dijadwalkan'
                                        }
                                    />
                                    <Detail
                                        icon={UserRound}
                                        label="PIC teknisi"
                                        value={
                                            order.technician?.name ||
                                            'Belum ditugaskan'
                                        }
                                    />
                                    <Detail
                                        icon={UserRound}
                                        label="Tim teknisi"
                                        value={
                                            order.technician_team
                                                .map(
                                                    (technician) =>
                                                        technician.name,
                                                )
                                                .join(', ') ||
                                            'Belum ditugaskan'
                                        }
                                    />
                                    <Detail
                                        icon={ShieldCheck}
                                        label="Supervisor"
                                        value={
                                            order.supervisor ||
                                            'Menunggu triase'
                                        }
                                    />
                                </dl>
                            </section>

                            {order.technician && (
                                <section className="liquid-panel rounded-[26px] p-5">
                                    <p className="section-kicker text-cyan">
                                        PIC terhubung
                                    </p>
                                    <div className="mt-4 flex items-start gap-3">
                                        <Avatar className="size-14 border-2 border-white shadow-md">
                                            <AvatarImage
                                                src={
                                                    order.technician
                                                        .avatar_url ?? undefined
                                                }
                                                alt={order.technician.name}
                                            />
                                            <AvatarFallback className="bg-cyan/10 text-cyan font-semibold">
                                                {initials(
                                                    order.technician.name,
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div>
                                            <h2 className="text-foreground font-semibold">
                                                {order.technician.name}
                                            </h2>
                                            <p className="text-cyan mt-1 text-xs font-bold">
                                                {order.technician.specialty ??
                                                    'PIC teknisi AD Com'}
                                            </p>
                                            <p className="mt-2 flex items-center gap-1 text-xs text-amber-600">
                                                <Star className="size-3.5 fill-current" />
                                                <span className="font-semibold">
                                                    {order.technician
                                                        .rating_average === null
                                                        ? 'Teknisi baru'
                                                        : order.technician.rating_average.toFixed(
                                                              1,
                                                          )}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    (
                                                    {
                                                        order.technician
                                                            .reviews_count
                                                    }{' '}
                                                    ulasan)
                                                </span>
                                            </p>
                                            {order.technician.phone && (
                                                <a
                                                    href={`tel:${order.technician.phone}`}
                                                    className="text-muted-foreground hover:text-cyan mt-2 inline-flex items-center gap-1.5 text-xs font-bold"
                                                >
                                                    <Phone className="size-3.5" />{' '}
                                                    {order.technician.phone}
                                                </a>
                                            )}
                                        </div>
                                    </div>
                                    {order.technician.bio && (
                                        <p className="text-muted-foreground mt-4 text-xs leading-5">
                                            {order.technician.bio}
                                        </p>
                                    )}
                                    <p className="border-ink/8 text-muted-foreground mt-4 border-t pt-3 text-[10px]">
                                        Kontak ditampilkan atas persetujuan
                                        teknisi. Gunakan hanya untuk tiket ini.
                                    </p>
                                </section>
                            )}

                            {order.estimate && (
                                <section className="liquid-panel rounded-[26px] p-5">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="section-kicker text-cyan">
                                                Estimasi v
                                                {order.estimate.version}
                                            </p>
                                            <h2 className="mt-1 font-semibold">
                                                Rincian biaya
                                            </h2>
                                        </div>
                                        <ReceiptText className="text-cyan" />
                                    </div>
                                    <div className="mt-5 space-y-3">
                                        {order.estimate.items.map((item) => (
                                            <div
                                                key={item.label}
                                                className="flex items-start justify-between gap-4 text-xs"
                                            >
                                                <span className="text-muted-foreground">
                                                    {item.label}
                                                    {Number(item.quantity) !==
                                                        1 &&
                                                        ` × ${Number(item.quantity)}`}
                                                </span>
                                                <span className="font-bold">
                                                    {money.format(item.total)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="border-ink/10 mt-5 flex items-center justify-between border-t pt-4">
                                        <span className="text-sm font-semibold">
                                            Total estimasi
                                        </span>
                                        <span className="text-lg font-semibold">
                                            {money.format(order.estimate.total)}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground mt-3 text-[11px]">
                                        Status:{' '}
                                        {order.estimate.status === 'approved'
                                            ? 'Disetujui'
                                            : 'Menunggu persetujuan'}
                                        {order.estimate.valid_until &&
                                            ` · Berlaku sampai ${order.estimate.valid_until}`}
                                    </p>
                                </section>
                            )}

                            <section className="liquid-panel rounded-[26px] p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Status pembayaran
                                        </p>
                                        <h2 className="mt-1 font-semibold">
                                            Jejak dana tiket
                                        </h2>
                                    </div>
                                    <WalletCards className="text-cyan" />
                                </div>
                                <div className="mt-5 grid grid-cols-2 gap-3">
                                    <div className="surface-tile rounded-2xl p-3">
                                        <p className="text-muted-foreground text-[10px] font-semibold uppercase">
                                            Terverifikasi
                                        </p>
                                        <p className="text-foreground mt-2 font-semibold">
                                            {money.format(
                                                order.payment.verified,
                                            )}
                                        </p>
                                    </div>
                                    <div className="surface-tile rounded-2xl p-3">
                                        <p className="text-muted-foreground text-[10px] font-semibold uppercase">
                                            Diproses
                                        </p>
                                        <p className="text-foreground mt-2 font-semibold">
                                            {money.format(
                                                order.payment.pending,
                                            )}
                                        </p>
                                    </div>
                                </div>
                                {order.payment.last_reference && (
                                    <p className="text-muted-foreground mt-4 text-[11px]">
                                        Referensi terakhir:{' '}
                                        <span className="text-foreground font-mono font-bold">
                                            {order.payment.last_reference}
                                        </span>
                                    </p>
                                )}
                                <p className="text-muted-foreground mt-3 text-[10px]">
                                    Nominal terverifikasi berasal dari ledger
                                    internal AD Com dan terhubung langsung ke
                                    tiket ini.
                                </p>
                            </section>

                            <Button
                                variant="outline"
                                asChild
                                className="border-border h-11 w-full rounded-full bg-transparent"
                            >
                                <Link href={trackIndex()}>
                                    <ArrowLeft /> Lacak tiket lain
                                </Link>
                            </Button>
                        </aside>
                    </div>
                </div>
            </main>
            <AdComAssistant />
        </PublicShell>
    );
}

function Detail({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof MapPin;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-start gap-3">
            <span className="bg-cyan/10 text-cyan grid size-9 shrink-0 place-items-center rounded-xl">
                <Icon className="size-4" />
            </span>
            <div>
                <dt className="text-muted-foreground text-xs">{label}</dt>
                <dd className="text-foreground mt-0.5 font-bold">{value}</dd>
            </div>
        </div>
    );
}

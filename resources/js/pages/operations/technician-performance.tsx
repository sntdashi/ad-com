import { Head } from '@inertiajs/react';
import {
    Activity,
    Award,
    BadgeAlert,
    BarChart3,
    MessageSquareText,
    Star,
    UsersRound,
} from 'lucide-react';
import TechnicianPerformanceController from '@/actions/App/Http/Controllers/TechnicianPerformanceController';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';

type Technician = {
    id: number;
    name: string;
    username: string | null;
    avatar_url: string | null;
    specialty: string | null;
    is_active: boolean;
    rating_average: number | null;
    quality_average: number | null;
    communication_average: number | null;
    punctuality_average: number | null;
    reviews_count: number;
    low_rating_reviews_count: number;
    completed_assignments_count: number;
    performance_level:
        | 'data_awal'
        | 'perlu_pendampingan'
        | 'perlu_dipantau'
        | 'baik';
    work?: {
        completed_jobs_count: number;
        avg_resolution_minutes: number | null;
        avg_work_session_minutes: number | null;
        sla_compliance_rate: number;
        reopened_jobs_count: number;
    };
};

type Review = {
    id: number;
    ticket_number: string;
    service: string;
    technician: string;
    rating: number;
    quality_rating: number;
    communication_rating: number;
    punctuality_rating: number;
    tags: string[];
    comment: string | null;
    created_at: string;
};

type Summary = {
    technicians: number;
    reviews: number;
    average: number | null;
    needs_attention: number;
};

const levelLabels = {
    data_awal: [
        'Data awal',
        'border border-border bg-muted text-muted-foreground',
    ],
    perlu_pendampingan: ['Perlu pendampingan', 'status-pill-danger'],
    perlu_dipantau: ['Perlu dipantau', 'status-pill-warning'],
    baik: ['Kinerja baik', 'status-pill-success'],
} as const;

const tagLabels: Record<string, string> = {
    rapi: 'Rapi',
    komunikatif: 'Komunikatif',
    tepat_waktu: 'Tepat waktu',
    solutif: 'Solutif',
    ramah: 'Ramah',
};

const initials = (name: string) =>
    name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

export default function TechnicianPerformance({
    role,
    technicians,
    summary,
    distribution,
    recentReviews,
}: {
    role: string;
    technicians: Technician[];
    summary: Summary;
    distribution: Record<string, number>;
    recentReviews: Review[];
}) {
    const cards = [
        [
            'Teknisi terukur',
            summary.technicians,
            UsersRound,
            'bg-cyan/10 text-cyan',
        ],
        [
            'Ulasan terverifikasi',
            summary.reviews,
            MessageSquareText,
            'bg-violet-100 text-violet-700',
        ],
        [
            'Rata-rata tim',
            summary.average?.toFixed(2) ?? '—',
            Star,
            'status-pill-warning',
        ],
        [
            'Perlu tindak lanjut',
            summary.needs_attention,
            BadgeAlert,
            'status-pill-danger',
        ],
    ] as const;

    return (
        <>
            <Head title="Evaluasi Teknisi" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl">
                    <header className="liquid-panel-dark rounded-[28px] p-6 text-white sm:p-8">
                        <p className="text-cyan text-xs font-black tracking-[.15em] uppercase">
                            Performance intelligence
                        </p>
                        <div className="mt-3 flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
                            <div>
                                <h1 className="text-3xl font-black tracking-[-.045em] sm:text-4xl">
                                    {role === 'technician'
                                        ? 'Performa kerjamu, transparan.'
                                        : 'Evaluasi adil berbasis pekerjaan nyata.'}
                                </h1>
                                <p className="mt-3 max-w-2xl text-sm leading-6 text-white/58">
                                    Hanya customer pemilik tiket selesai yang
                                    bisa memberi nilai. Rating rendah menjadi
                                    sinyal pendampingan, bukan hukuman otomatis.
                                </p>
                            </div>
                            <div className="border-lime/25 bg-lime/10 rounded-2xl border px-5 py-3">
                                <p className="text-lime text-[10px] font-black uppercase">
                                    Integritas data
                                </p>
                                <p className="mt-1 flex items-center gap-2 font-black">
                                    <Award className="size-5" /> 1 tiket · 1
                                    nilai
                                </p>
                            </div>
                        </div>
                    </header>

                    <section
                        className="mt-5 grid grid-cols-2 gap-3 xl:grid-cols-4"
                        aria-label="Ringkasan evaluasi"
                    >
                        {cards.map(([label, value, Icon, tone]) => (
                            <article
                                key={label}
                                className="liquid-panel rounded-[22px] p-4 sm:p-5"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs font-bold">
                                            {label}
                                        </p>
                                        <p className="text-foreground mt-3 text-3xl font-black tracking-[-.04em]">
                                            {value}
                                        </p>
                                    </div>
                                    <span
                                        className={`grid size-10 shrink-0 place-items-center rounded-xl ${tone}`}
                                    >
                                        <Icon className="size-5" />
                                    </span>
                                </div>
                            </article>
                        ))}
                    </section>

                    <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px] xl:items-start">
                        <section className="space-y-4">
                            {technicians.map((technician) => (
                                <TechnicianCard
                                    key={technician.id}
                                    technician={technician}
                                />
                            ))}
                            {technicians.length === 0 && (
                                <div className="liquid-panel grid min-h-64 place-items-center rounded-[26px] p-8 text-center">
                                    <div>
                                        <UsersRound className="text-cyan mx-auto size-8" />
                                        <p className="text-ink mt-3 font-black">
                                            Belum ada data teknisi.
                                        </p>
                                    </div>
                                </div>
                            )}
                        </section>

                        <aside className="space-y-5 xl:sticky xl:top-5">
                            <DistributionCard
                                distribution={distribution}
                                total={summary.reviews}
                            />
                            <section className="liquid-panel rounded-[26px] p-5">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Ulasan terbaru
                                        </p>
                                        <h2 className="text-ink mt-1 font-black">
                                            Suara pelanggan
                                        </h2>
                                    </div>
                                    <Activity className="text-cyan size-5" />
                                </div>
                                <div className="mt-4 space-y-4">
                                    {recentReviews.map((review) => (
                                        <ReviewCard
                                            key={review.id}
                                            review={review}
                                            showTechnician={
                                                role !== 'technician'
                                            }
                                        />
                                    ))}
                                    {recentReviews.length === 0 && (
                                        <p className="text-muted-foreground text-xs leading-5">
                                            Belum ada ulasan terverifikasi.
                                        </p>
                                    )}
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>
            </div>
        </>
    );
}

function TechnicianCard({ technician }: { technician: Technician }) {
    const [levelLabel, levelTone] = levelLabels[technician.performance_level];

    return (
        <article className="liquid-panel rounded-[26px] p-5 sm:p-6">
            <div className="flex flex-col gap-5 lg:flex-row lg:items-center">
                <div className="flex min-w-0 flex-1 items-center gap-4">
                    <Avatar className="size-16 shrink-0 border-2 border-white shadow-lg">
                        <AvatarImage
                            src={technician.avatar_url ?? undefined}
                            alt={technician.name}
                        />
                        <AvatarFallback className="bg-cyan/10 text-cyan font-black">
                            {initials(technician.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-ink truncate text-lg font-black">
                                {technician.name}
                            </h2>
                            <span
                                className={`rounded-full px-2.5 py-1 text-[9px] font-black ${levelTone}`}
                            >
                                {levelLabel}
                            </span>
                        </div>
                        <p className="text-cyan mt-1 text-xs font-bold">
                            {technician.specialty ?? 'Teknisi AD Com'}
                        </p>
                        <p className="text-ink/38 mt-1 text-[11px]">
                            {technician.username
                                ? `@${technician.username} · `
                                : ''}
                            {technician.completed_assignments_count} pekerjaan
                            selesai
                        </p>
                    </div>
                </div>
                <div className="status-pill-warning flex items-center gap-3 rounded-2xl border px-4 py-3">
                    <Star className="size-7 fill-current" />
                    <div>
                        <p className="text-2xl leading-none font-black">
                            {technician.rating_average?.toFixed(2) ?? '—'}
                        </p>
                        <p className="mt-1 text-[10px] font-bold">
                            {technician.reviews_count} ulasan
                        </p>
                    </div>
                </div>
            </div>
            <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Metric label="Kualitas" value={technician.quality_average} />
                <Metric
                    label="Komunikasi"
                    value={technician.communication_average}
                />
                <Metric
                    label="Ketepatan"
                    value={technician.punctuality_average}
                />
                <Metric
                    label="Rating ≤2"
                    value={technician.low_rating_reviews_count}
                    alert={technician.low_rating_reviews_count > 0}
                />
            </div>

            {technician.work && (
                <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Metric
                        label="Kepatuhan SLA"
                        value={technician.work.sla_compliance_rate}
                        suffix="%"
                        alert={technician.work.sla_compliance_rate < 80}
                    />
                    <Metric
                        label="Rata Resolusi"
                        value={technician.work.avg_resolution_minutes}
                        suffix=" mnt"
                    />
                    <Metric
                        label="Rata Sesi Kerja"
                        value={technician.work.avg_work_session_minutes}
                        suffix=" mnt"
                    />
                    <Metric
                        label="Tiket Reopened"
                        value={technician.work.reopened_jobs_count}
                        alert={technician.work.reopened_jobs_count > 0}
                    />
                </div>
            )}
        </article>
    );
}

function Metric({
    label,
    value,
    suffix = '',
    alert = false,
}: {
    label: string;
    value: number | null;
    suffix?: string;
    alert?: boolean;
}) {
    return (
        <div
            className={`rounded-2xl border p-3 ${alert ? 'status-pill-danger' : 'surface-tile'}`}
        >
            <p className="text-muted-foreground text-[10px] font-black uppercase">
                {label}
            </p>
            <p
                className={`mt-1 text-lg font-black ${alert ? 'text-[var(--brand-text)]' : 'text-foreground'}`}
            >
                {typeof value === 'number'
                    ? `${value.toFixed(2).replace('.00', '')}${suffix}`
                    : '—'}
            </p>
        </div>
    );
}

function DistributionCard({
    distribution,
    total,
}: {
    distribution: Record<string, number>;
    total: number;
}) {
    return (
        <section className="liquid-panel rounded-[26px] p-5">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="section-kicker text-cyan">Distribusi nilai</p>
                    <h2 className="text-ink mt-1 font-black">
                        Konsistensi layanan
                    </h2>
                </div>
                <BarChart3 className="text-cyan size-5" />
            </div>
            <div className="mt-5 space-y-3">
                {[5, 4, 3, 2, 1].map((rating) => {
                    const count = Number(distribution[String(rating)] ?? 0);
                    const percentage = total === 0 ? 0 : (count / total) * 100;

                    return (
                        <div
                            key={rating}
                            className="grid grid-cols-[36px_1fr_24px] items-center gap-2 text-xs"
                        >
                            <span className="flex items-center gap-1 font-black text-amber-600">
                                {rating}
                                <Star className="size-3 fill-current" />
                            </span>
                            <span className="bg-muted h-2 overflow-hidden rounded-full">
                                <span
                                    className="bg-cyan block h-full rounded-full"
                                    style={{ width: `${percentage}%` }}
                                />
                            </span>
                            <span className="text-muted-foreground text-right font-bold">
                                {count}
                            </span>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

function ReviewCard({
    review,
    showTechnician,
}: {
    review: Review;
    showTechnician: boolean;
}) {
    return (
        <article className="border-border border-t pt-4 first:border-t-0 first:pt-0">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-cyan font-mono text-[9px] font-black">
                        {review.ticket_number}
                    </p>
                    <p className="text-foreground mt-1 text-xs font-black">
                        {review.service}
                    </p>
                    {showTechnician && (
                        <p className="text-muted-foreground mt-1 text-[10px]">
                            {review.technician}
                        </p>
                    )}
                </div>
                <span className="status-pill-warning flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black">
                    <Star className="size-3 fill-current" />
                    {review.rating}
                </span>
            </div>
            {review.tags.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {review.tags.map((tag) => (
                        <span
                            key={tag}
                            className="bg-cyan/10 text-cyan rounded-full px-2 py-1 text-[9px] font-bold"
                        >
                            {tagLabels[tag] ?? tag}
                        </span>
                    ))}
                </div>
            )}
            {review.comment && (
                <p className="text-muted-foreground mt-2 text-xs leading-5">
                    “{review.comment}”
                </p>
            )}
            <p className="text-muted-foreground/70 mt-2 text-[9px]">
                {review.created_at}
            </p>
        </article>
    );
}

TechnicianPerformance.layout = {
    breadcrumbs: [
        {
            title: 'Evaluasi teknisi',
            href: TechnicianPerformanceController(),
        },
    ],
};

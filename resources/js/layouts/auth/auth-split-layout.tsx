import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, KeyRound, ShieldCheck, TicketCheck } from 'lucide-react';
import { ThemeToggle } from '@/components/appearance-tabs';
import BrandMark from '@/components/brand-mark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const trustHighlights = [
    {
        icon: TicketCheck,
        title: 'Pelacakan Servis Terstruktur',
        detail: 'Pantau status tiket, estimasi biaya, hingga riwayat garansi secara real-time.',
    },
    {
        icon: ShieldCheck,
        title: 'Akses Berbasis Peran & OTP',
        detail: 'Verifikasi dua faktor dan persetujuan teraudit untuk menjaga kendali tim internal.',
    },
    {
        icon: KeyRound,
        title: 'Dukungan Passkey & Google Sign-In',
        detail: 'Masuk cepat menggunakan kredensial perangkat atau akun terhubung.',
    },
];

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="bg-background text-foreground relative grid min-h-svh lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
            <aside className="liquid-panel-dark relative hidden flex-col justify-between overflow-hidden rounded-none border-y-0 border-l-0 p-10 text-white lg:flex xl:p-14">
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_18%_15%,rgba(196,46,68,0.28),transparent_32rem),radial-gradient(circle_at_85%_85%,rgba(255,255,255,0.06),transparent_26rem)]"
                />
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 [background-image:linear-gradient(to_right,rgba(255,255,255,0.07)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.07)_1px,transparent_1px)] [background-size:48px_48px] opacity-20"
                />

                <div className="relative z-10 flex items-center justify-between">
                    <Link
                        href={home()}
                        className="inline-flex items-center gap-3 rounded-2xl focus-visible:outline-none"
                    >
                        <span className="flex size-11 items-center justify-center rounded-2xl bg-white p-2 shadow-sm">
                            <BrandMark tone="brand" className="size-full" />
                        </span>
                        <div>
                            <p className="text-base font-bold tracking-tight text-white">
                                {name}
                            </p>
                            <p className="text-[11px] font-semibold tracking-[0.12em] text-white/60 uppercase">
                                TEFA TKJ · Service Portal
                            </p>
                        </div>
                    </Link>
                </div>

                <div className="relative z-10 max-w-lg space-y-8">
                    <div className="space-y-3">
                        <h2 className="text-3xl leading-[1.15] font-semibold tracking-[-0.03em] text-white xl:text-4xl">
                            Operasional layanan IT, inventaris, dan pelanggan
                            dalam satu kendali.
                        </h2>
                        <p className="text-sm leading-6 text-white/70">
                            Dirancang untuk tim Teaching Factory TKJ dan
                            pelanggan AD Com agar setiap tiket, estimasi, dan
                            pengerjaan teknisi berjalan presisi.
                        </p>
                    </div>

                    <div className="grid gap-3">
                        {trustHighlights.map(
                            ({ icon: Icon, title: itemTitle, detail }) => (
                                <div
                                    key={itemTitle}
                                    className="flex items-start gap-3.5 rounded-2xl border border-white/10 bg-white/[0.04] p-4 backdrop-blur-sm"
                                >
                                    <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-white/10 text-rose-300">
                                        <Icon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold text-white">
                                            {itemTitle}
                                        </p>
                                        <p className="mt-0.5 text-xs leading-5 text-white/65">
                                            {detail}
                                        </p>
                                    </div>
                                </div>
                            ),
                        )}
                    </div>
                </div>

                <div className="relative z-10 flex items-center justify-between border-t border-white/10 pt-5 text-xs text-white/55">
                    <span>AD Com · Manajemen Layanan & Bengkel TKJ</span>
                    <span>Terintegrasi &amp; Teraudit</span>
                </div>
            </aside>

            <main className="flex flex-col justify-between px-5 py-6 sm:px-8 lg:px-12 lg:py-10">
                <div className="mx-auto flex w-full max-w-[440px] items-center justify-between gap-3">
                    <Link
                        href={home()}
                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-xs font-medium transition-colors"
                    >
                        <ArrowLeft className="size-3.5" aria-hidden="true" />
                        <span>Kembali ke beranda</span>
                    </Link>
                    <ThemeToggle />
                </div>

                <div className="mx-auto my-auto w-full max-w-[440px] py-8">
                    <div className="mb-6 flex flex-col items-center text-center lg:hidden">
                        <Link
                            href={home()}
                            className="border-border mb-3 flex size-14 items-center justify-center rounded-2xl border bg-white p-2.5 shadow-xs"
                        >
                            <BrandMark tone="brand" className="size-full" />
                        </Link>
                        <p className="text-xs font-semibold tracking-[0.1em] text-[var(--brand-text)] uppercase">
                            AD Com · TEFA TKJ
                        </p>
                    </div>

                    <div className="border-border rounded-[24px] border bg-[var(--surface-1)] p-6 shadow-[var(--shadow-glass-md)] sm:p-8">
                        <header className="mb-6 space-y-1.5">
                            <h1 className="text-foreground text-2xl font-semibold tracking-[-0.025em]">
                                {title}
                            </h1>
                            {description && (
                                <p className="text-muted-foreground text-sm leading-6">
                                    {description}
                                </p>
                            )}
                        </header>

                        {children}
                    </div>
                </div>

                <p className="text-muted-foreground mx-auto w-full max-w-[440px] text-center text-xs">
                    Butuh bantuan akses akun? Hubungi admin operasional AD Com.
                </p>
            </main>
        </div>
    );
}

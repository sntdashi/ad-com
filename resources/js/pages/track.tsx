import { Head, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Clock3,
    KeyRound,
    Search,
    ShieldCheck,
    TicketCheck,
} from 'lucide-react';
import type { FormEvent } from 'react';
import AdComAssistant from '@/components/adcom-assistant';
import InputError from '@/components/input-error';
import PublicShell from '@/components/public-shell';
import { Button } from '@/components/ui/button';
import { lookup } from '@/routes/track';

export default function Track() {
    const { data, setData, post, processing, errors } = useForm({
        ticket_number: '',
        access_code: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post(lookup.url(), { preserveScroll: true });
    }

    return (
        <PublicShell>
            <Head title="Lacak Tiket">
                <meta
                    name="description"
                    content="Lihat status, estimasi, tim, dan riwayat layanan AD Com dengan nomor tiket serta kode akses privat."
                />
            </Head>

            <main className="liquid-panel-dark min-h-[calc(100vh-9rem)] rounded-none border-x-0 border-b-0 text-white">
                <div>
                    <div className="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 sm:py-20 lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:gap-20 lg:px-8 lg:py-24">
                        <section>
                            <p className="section-kicker text-lime">
                                Pelacakan pesanan
                            </p>
                            <h1 className="mt-4 text-3xl leading-tight font-semibold tracking-[-0.03em] sm:text-4xl">
                                Lacak tiket layanan
                            </h1>
                            <p className="mt-6 max-w-lg text-base leading-7 text-white/58">
                                Buka satu tiket untuk melihat status terbaru,
                                siapa yang menangani, jadwal, dan rincian
                                estimasi yang sudah dibuat.
                            </p>
                            <div className="mt-9 grid gap-3 text-sm sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
                                {[
                                    [Clock3, 'Status aktual'],
                                    [TicketCheck, 'Jejak pekerjaan'],
                                    [ShieldCheck, 'Akses privat'],
                                ].map(([Icon, label]) => {
                                    const ItemIcon = Icon as typeof Clock3;
                                    return (
                                        <div
                                            key={label as string}
                                            className="liquid-control glass-pointer-lit flex items-center gap-2 rounded-xl border-white/15 bg-white/[0.06] px-3 py-3"
                                        >
                                            <ItemIcon className="text-cyan size-4" />
                                            <span className="font-bold">
                                                {label as string}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>

                        <section className="liquid-panel text-foreground rounded-2xl p-6 sm:p-9">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        Akses tiket
                                    </p>
                                    <h2 className="mt-2 text-2xl font-semibold tracking-[-0.04em]">
                                        Masukkan dua kode ini
                                    </h2>
                                </div>
                                <span className="bg-lime grid size-12 shrink-0 place-items-center rounded-2xl text-slate-950">
                                    <Search />
                                </span>
                            </div>

                            <form onSubmit={submit} className="mt-7 space-y-5">
                                <label
                                    className="block text-sm font-semibold"
                                    htmlFor="ticket_number"
                                >
                                    Nomor tiket
                                    <div className="relative mt-2">
                                        <TicketCheck className="text-muted-foreground absolute top-1/2 left-4 size-5 -translate-y-1/2" />
                                        <input
                                            id="ticket_number"
                                            value={data.ticket_number}
                                            onChange={(e) =>
                                                setData(
                                                    'ticket_number',
                                                    e.target.value.toUpperCase(),
                                                )
                                            }
                                            className="liquid-input border-ink/15 placeholder:text-muted-foreground focus:border-cyan focus:ring-cyan/15 h-13 w-full rounded-xl border pr-4 pl-12 font-mono text-sm font-bold tracking-wider outline-none focus:ring-3"
                                            placeholder="ADC-2609-0101"
                                            autoComplete="off"
                                        />
                                    </div>
                                    <InputError
                                        message={errors.ticket_number}
                                        className="mt-2"
                                    />
                                </label>
                                <label
                                    className="block text-sm font-semibold"
                                    htmlFor="access_code"
                                >
                                    Kode akses 8 karakter
                                    <div className="relative mt-2">
                                        <KeyRound className="text-muted-foreground absolute top-1/2 left-4 size-5 -translate-y-1/2" />
                                        <input
                                            id="access_code"
                                            value={data.access_code}
                                            onChange={(e) =>
                                                setData(
                                                    'access_code',
                                                    e.target.value
                                                        .toUpperCase()
                                                        .replace(
                                                            /[^A-Z0-9]/g,
                                                            '',
                                                        )
                                                        .slice(0, 8),
                                                )
                                            }
                                            className="liquid-input border-ink/15 placeholder:text-muted-foreground focus:border-cyan focus:ring-cyan/15 h-13 w-full rounded-xl border pr-4 pl-12 font-mono text-sm font-bold tracking-[0.22em] outline-none focus:ring-3"
                                            placeholder="ADCOM101"
                                            maxLength={8}
                                            autoComplete="one-time-code"
                                        />
                                    </div>
                                    <InputError
                                        message={errors.access_code}
                                        className="mt-2"
                                    />
                                </label>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="h-13 w-full rounded-xl text-base"
                                >
                                    {processing
                                        ? 'Mencari tiket…'
                                        : 'Buka progres tiket'}{' '}
                                    <ArrowRight className="size-5" />
                                </Button>
                            </form>

                            <div className="liquid-control border-cyan/20 bg-cyan/[0.08] text-muted-foreground mt-6 rounded-2xl p-4 text-xs leading-5">
                                <p className="text-foreground font-semibold">
                                    Coba data demo
                                </p>
                                <p className="mt-1 font-mono">
                                    ADC-2609-0101 · ADCOM101
                                </p>
                            </div>
                        </section>
                    </div>
                </div>
            </main>
            <AdComAssistant />
        </PublicShell>
    );
}

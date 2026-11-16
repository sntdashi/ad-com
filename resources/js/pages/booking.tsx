import { Head, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    Camera,
    Check,
    Computer,
    MapPin,
    Network,
    Router,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useMemo, useState, type FormEvent } from 'react';
import AdComAssistant from '@/components/adcom-assistant';
import InputError from '@/components/input-error';
import {
    MotionStagger,
    MotionStaggerItem,
} from '@/components/motion/MotionStagger';
import { TiltSurface } from '@/components/motion/TiltSurface';
import PublicShell from '@/components/public-shell';
import { Button } from '@/components/ui/button';
import { VARIANTS } from '@/lib/motion';
import { store as storeBooking } from '@/routes/booking';

type Service = {
    id: number;
    code: string;
    slug: string;
    name: string;
    summary: string;
    pricing_label: string;
    service_mode: 'onsite' | 'dropoff' | 'remote';
    risk_level: string;
    icon: string;
};

type BookingData = {
    service_id: string;
    customer_name: string;
    phone: string;
    email: string;
    address: string;
    district: string;
    service_mode: 'onsite' | 'dropoff' | 'remote';
    complaint: string;
    preferred_date: string;
    preferred_window: string;
    urgency: 'normal' | 'soon';
    privacy_accepted: boolean;
};

const icons = {
    router: Router,
    camera: Camera,
    computer: Computer,
    network: Network,
    wrench: Wrench,
};
const inputClass =
    'liquid-input mt-2 h-12 w-full rounded-xl border border-ink/15 px-4 text-sm text-foreground outline-none placeholder:text-muted-foreground focus:border-cyan focus:ring-3 focus:ring-cyan/15';
const textareaClass =
    'liquid-input mt-2 min-h-32 w-full resize-y rounded-xl border border-ink/15 px-4 py-3 text-sm leading-6 text-foreground outline-none placeholder:text-muted-foreground focus:border-cyan focus:ring-3 focus:ring-cyan/15';

export default function Booking({
    services,
    selectedServiceSlug,
}: {
    services: Service[];
    selectedServiceSlug?: string;
}) {
    const { auth } = usePage<{
        auth?: {
            user?: {
                name?: string;
                email?: string;
                phone?: string | null;
            } | null;
        };
    }>().props;
    const initial =
        services.find((service) => service.slug === selectedServiceSlug) ??
        services[0];
    const [step, setStep] = useState(1);
    const shouldReduceMotion = useReducedMotion();
    const { data, setData, post, processing, errors } = useForm<BookingData>({
        service_id: initial?.id.toString() ?? '',
        customer_name: auth.user?.name ?? '',
        phone: typeof auth.user?.phone === 'string' ? auth.user.phone : '',
        email: auth.user?.email ?? '',
        address: '',
        district: '',
        service_mode: initial?.service_mode ?? 'onsite',
        complaint: '',
        preferred_date: '',
        preferred_window: '',
        urgency: 'normal',
        privacy_accepted: false,
    });

    const selected = useMemo(
        () =>
            services.find(
                (service) => service.id.toString() === data.service_id,
            ),
        [data.service_id, services],
    );
    const SelectedIcon = selected
        ? (icons[selected.icon as keyof typeof icons] ?? Wrench)
        : Wrench;
    function selectService(service: Service) {
        setData((current) => ({
            ...current,
            service_id: service.id.toString(),
            service_mode: service.service_mode,
        }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        post(storeBooking.url(), {
            preserveScroll: true,
            onError: (serverErrors) =>
                setStep(
                    serverErrors.service_id
                        ? 1
                        : serverErrors.customer_name ||
                            serverErrors.phone ||
                            serverErrors.email ||
                            serverErrors.address ||
                            serverErrors.district
                          ? 2
                          : 3,
                ),
        });
    }

    return (
        <PublicShell>
            <Head title="Buat Pesanan">
                <meta
                    name="description"
                    content="Buat tiket layanan jaringan, CCTV, servis komputer, atau konsultasi IT di AD Com."
                />
            </Head>

            <main className="relative min-h-[calc(100vh-9rem)] overflow-x-clip py-10 sm:py-14">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <motion.div
                        variants={VARIANTS.sectionHeaderContainer}
                        initial={shouldReduceMotion ? false : 'hidden'}
                        animate="visible"
                        className="mb-8 grid gap-6 lg:grid-cols-[1fr_auto] lg:items-end"
                    >
                        <div>
                            <motion.p
                                variants={VARIANTS.sectionHeaderItem}
                                className="section-kicker text-cyan"
                            >
                                Buat tiket layanan
                            </motion.p>
                            <motion.h1
                                variants={VARIANTS.sectionHeaderItem}
                                className="mt-3 max-w-3xl text-3xl leading-tight font-semibold tracking-[-0.03em] sm:text-4xl"
                            >
                                Ajukan layanan
                            </motion.h1>
                            <motion.p
                                variants={VARIANTS.sectionHeaderItem}
                                className="text-muted-foreground mt-4 max-w-2xl text-sm leading-6"
                            >
                                Formulir ini membuat permintaan awal, bukan
                                langsung menyetujui biaya. Tim akan melakukan
                                triase dan mengirim estimasi terlebih dahulu.
                            </motion.p>
                        </div>
                        <motion.div
                            variants={VARIANTS.sectionHeaderItem}
                            className="flex gap-2"
                            aria-label={`Langkah ${step} dari 3`}
                        >
                            {[1, 2, 3].map((number) => (
                                <span
                                    key={number}
                                    className={`h-2 rounded-full transition-all duration-300 ${number === step ? 'bg-cyan w-12' : number < step ? 'bg-lime ring-ink/15 w-7 ring-1' : 'bg-ink/12 w-7'}`}
                                />
                            ))}
                        </motion.div>
                    </motion.div>

                    <form
                        onSubmit={submit}
                        className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start"
                    >
                        <section className="liquid-panel rounded-2xl p-5 sm:p-8">
                            {step === 1 && (
                                <div>
                                    <p className="text-brand-deep dark:text-primary font-mono text-xs font-semibold">
                                        01 / Pilih kebutuhan
                                    </p>
                                    <h2 className="mt-2 text-2xl font-semibold tracking-[-0.035em]">
                                        Layanan mana yang paling mendekati?
                                    </h2>
                                    <MotionStagger className="mt-6 grid gap-3 sm:grid-cols-2">
                                        {services.map((service) => {
                                            const Icon =
                                                icons[
                                                    service.icon as keyof typeof icons
                                                ] ?? Wrench;
                                            const active =
                                                data.service_id ===
                                                service.id.toString();
                                            return (
                                                <MotionStaggerItem
                                                    key={service.id}
                                                >
                                                    <TiltSurface className="rounded-xl">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                selectService(
                                                                    service,
                                                                )
                                                            }
                                                            className={`group focus-visible:ring-ring/35 relative w-full rounded-xl border p-5 text-left transition-[background-color,border-color,box-shadow,transform] duration-200 focus-visible:ring-3 focus-visible:outline-none active:scale-[0.985] ${active ? 'border-primary bg-primary text-primary-foreground shadow-[0_14px_32px_rgba(169,37,59,0.24)]' : 'border-border bg-card/90 hover:border-primary/40 shadow-xs'}`}
                                                            aria-pressed={
                                                                active
                                                            }
                                                        >
                                                            <div className="flex items-start justify-between gap-4">
                                                                <span
                                                                    className={`grid size-11 shrink-0 place-items-center rounded-xl transition-transform duration-200 ease-out group-hover:-translate-y-0.5 group-hover:scale-110 group-hover:rotate-[-3deg] ${active ? 'bg-white/20 text-white' : 'bg-primary/12 text-[var(--brand-text)]'}`}
                                                                >
                                                                    <Icon className="transition-transform duration-200 group-hover:scale-105" />
                                                                </span>
                                                                {active ? (
                                                                    <span className="grid size-6 shrink-0 place-items-center rounded-full bg-white text-[#8d1e31]">
                                                                        <Check
                                                                            className="size-4"
                                                                            aria-hidden="true"
                                                                        />
                                                                    </span>
                                                                ) : (
                                                                    <span
                                                                        aria-hidden="true"
                                                                        className="text-muted-foreground group-hover:text-brand-deep mt-1 grid size-6 shrink-0 place-items-center rounded-full transition-transform duration-200 group-hover:translate-x-1"
                                                                    >
                                                                        <ArrowRight className="size-4" />
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <h3 className="mt-4 font-semibold">
                                                                {service.name}
                                                            </h3>
                                                            <p
                                                                className={`mt-2 text-xs leading-5 ${active ? 'text-white/58' : 'text-muted-foreground'}`}
                                                            >
                                                                {
                                                                    service.summary
                                                                }
                                                            </p>
                                                            <p
                                                                className={`mt-5 text-xs font-semibold ${active ? 'text-lime' : 'text-foreground'}`}
                                                            >
                                                                {
                                                                    service.pricing_label
                                                                }
                                                            </p>
                                                        </button>
                                                    </TiltSurface>
                                                </MotionStaggerItem>
                                            );
                                        })}
                                    </MotionStagger>
                                    <InputError
                                        message={errors.service_id}
                                        className="mt-3"
                                    />
                                </div>
                            )}

                            {step === 2 && (
                                <div>
                                    <p className="text-brand-deep dark:text-primary font-mono text-xs font-semibold">
                                        02 / Kontak & lokasi
                                    </p>
                                    <h2 className="mt-2 text-2xl font-semibold tracking-[-0.035em]">
                                        Tim harus menghubungi siapa?
                                    </h2>
                                    <div className="mt-6 grid gap-5 sm:grid-cols-2">
                                        <Field
                                            label="Nama / nama usaha"
                                            error={errors.customer_name}
                                            required
                                        >
                                            <input
                                                className={inputClass}
                                                value={data.customer_name}
                                                onChange={(e) =>
                                                    setData(
                                                        'customer_name',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Contoh: Siti Rahma"
                                                autoComplete="name"
                                            />
                                        </Field>
                                        <Field
                                            label="Nomor WhatsApp"
                                            error={errors.phone}
                                            required
                                        >
                                            <input
                                                className={inputClass}
                                                value={data.phone}
                                                onChange={(e) =>
                                                    setData(
                                                        'phone',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="0812 3456 7890"
                                                inputMode="tel"
                                                autoComplete="tel"
                                            />
                                        </Field>
                                        <Field
                                            label="Email"
                                            hint="Opsional"
                                            error={errors.email}
                                        >
                                            <input
                                                className={inputClass}
                                                value={data.email}
                                                onChange={(e) =>
                                                    setData(
                                                        'email',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="nama@email.com"
                                                type="email"
                                                autoComplete="email"
                                            />
                                        </Field>
                                        <Field
                                            label="Kecamatan / wilayah"
                                            hint="Opsional"
                                            error={errors.district}
                                        >
                                            <input
                                                className={inputClass}
                                                value={data.district}
                                                onChange={(e) =>
                                                    setData(
                                                        'district',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Kecamatan ..."
                                            />
                                        </Field>
                                        <div className="sm:col-span-2">
                                            <Field
                                                label="Alamat lengkap"
                                                error={errors.address}
                                                required
                                            >
                                                <textarea
                                                    className={textareaClass}
                                                    value={data.address}
                                                    onChange={(e) =>
                                                        setData(
                                                            'address',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Nama jalan, nomor, RT/RW, patokan lokasi"
                                                    autoComplete="street-address"
                                                />
                                            </Field>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {step === 3 && (
                                <div>
                                    <p className="text-brand-deep dark:text-primary font-mono text-xs font-semibold">
                                        03 / Keluhan & waktu
                                    </p>
                                    <h2 className="mt-2 text-2xl font-semibold tracking-[-0.035em]">
                                        Apa yang terjadi?
                                    </h2>
                                    <div className="mt-6 grid gap-5 sm:grid-cols-2">
                                        <div className="sm:col-span-2">
                                            <Field
                                                label="Ceritakan masalahnya"
                                                error={errors.complaint}
                                                required
                                            >
                                                <textarea
                                                    className={`${textareaClass} min-h-40`}
                                                    value={data.complaint}
                                                    onChange={(e) =>
                                                        setData(
                                                            'complaint',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Sejak kapan terjadi, gejalanya seperti apa, dan perangkat apa yang terlibat?"
                                                />
                                            </Field>
                                            <p className="text-muted-foreground mt-2 text-right text-xs">
                                                {data.complaint.length}/3000
                                            </p>
                                        </div>
                                        <Field
                                            label="Tanggal yang diinginkan"
                                            hint="Opsional"
                                            error={errors.preferred_date}
                                        >
                                            <input
                                                className={inputClass}
                                                value={data.preferred_date}
                                                onChange={(e) =>
                                                    setData(
                                                        'preferred_date',
                                                        e.target.value,
                                                    )
                                                }
                                                type="date"
                                                min={new Date()
                                                    .toISOString()
                                                    .slice(0, 10)}
                                            />
                                        </Field>
                                        <Field
                                            label="Rentang waktu"
                                            hint="Opsional"
                                            error={errors.preferred_window}
                                        >
                                            <select
                                                className={inputClass}
                                                value={data.preferred_window}
                                                onChange={(e) =>
                                                    setData(
                                                        'preferred_window',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Pilih waktu
                                                </option>
                                                <option value="morning">
                                                    08.00–10.00
                                                </option>
                                                <option value="midday">
                                                    10.00–12.00
                                                </option>
                                                <option value="afternoon">
                                                    13.00–15.30
                                                </option>
                                            </select>
                                        </Field>
                                        <Field
                                            label="Prioritas"
                                            error={errors.urgency}
                                        >
                                            <select
                                                className={inputClass}
                                                value={data.urgency}
                                                onChange={(e) =>
                                                    setData(
                                                        'urgency',
                                                        e.target
                                                            .value as BookingData['urgency'],
                                                    )
                                                }
                                            >
                                                <option value="normal">
                                                    Normal, fleksibel
                                                </option>
                                                <option value="soon">
                                                    Segera, secepatnya
                                                </option>
                                            </select>
                                        </Field>
                                        <Field
                                            label="Mode layanan"
                                            error={errors.service_mode}
                                        >
                                            <select
                                                className={inputClass}
                                                value={data.service_mode}
                                                onChange={(e) =>
                                                    setData(
                                                        'service_mode',
                                                        e.target
                                                            .value as BookingData['service_mode'],
                                                    )
                                                }
                                            >
                                                <option value="onsite">
                                                    Kunjungan lokasi
                                                </option>
                                                <option value="dropoff">
                                                    Antar perangkat
                                                </option>
                                                <option value="remote">
                                                    Konsultasi jarak jauh
                                                </option>
                                            </select>
                                        </Field>
                                    </div>
                                    <label className="surface-tile mt-6 flex cursor-pointer items-start gap-3 rounded-2xl p-4 text-sm leading-6">
                                        <input
                                            type="checkbox"
                                            checked={data.privacy_accepted}
                                            onChange={(e) =>
                                                setData(
                                                    'privacy_accepted',
                                                    e.target.checked,
                                                )
                                            }
                                            className="accent-primary mt-1 size-4"
                                        />
                                        <span>
                                            Saya setuju data ini digunakan oleh
                                            tim AD Com untuk triase, komunikasi,
                                            dan pelaksanaan layanan.
                                        </span>
                                    </label>
                                    <InputError
                                        message={errors.privacy_accepted}
                                        className="mt-2"
                                    />
                                </div>
                            )}

                            <div className="border-ink/10 mt-8 flex items-center justify-between gap-3 border-t pt-6">
                                {step > 1 ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setStep(step - 1)}
                                        className="rounded-full"
                                    >
                                        <ArrowLeft /> Kembali
                                    </Button>
                                ) : (
                                    <span />
                                )}
                                {step < 3 ? (
                                    <Button
                                        type="button"
                                        onClick={() => setStep(step + 1)}
                                        disabled={
                                            step === 1 && !data.service_id
                                        }
                                        className="h-11 rounded-full px-6"
                                    >
                                        Lanjut <ArrowRight />
                                    </Button>
                                ) : (
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="h-11 rounded-full px-6"
                                    >
                                        {processing
                                            ? 'Membuat tiket…'
                                            : 'Buat tiket'}{' '}
                                        <ArrowRight />
                                    </Button>
                                )}
                            </div>
                        </section>

                        <aside className="space-y-4 lg:sticky lg:top-5">
                            <div className="liquid-panel-dark rounded-[24px] p-5 text-white">
                                <p className="section-kicker text-lime">
                                    Ringkasan
                                </p>
                                <div className="mt-5 flex items-start gap-3">
                                    <span className="bg-cyan grid size-10 shrink-0 place-items-center rounded-xl text-white">
                                        <SelectedIcon
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div>
                                        <p className="font-semibold">
                                            {selected?.name ?? 'Pilih layanan'}
                                        </p>
                                        <p className="mt-1 text-xs text-white/48">
                                            {selected?.pricing_label}
                                        </p>
                                    </div>
                                </div>
                                <div className="mt-5 space-y-3 border-t border-white/10 pt-5 text-xs text-white/58">
                                    <p className="flex items-center gap-2">
                                        <MapPin className="text-cyan size-4" />{' '}
                                        {data.address || 'Lokasi belum diisi'}
                                    </p>
                                    <p className="flex items-center gap-2">
                                        <CalendarDays className="text-cyan size-4" />{' '}
                                        {data.preferred_date ||
                                            'Jadwal fleksibel'}
                                    </p>
                                </div>
                            </div>
                            <div className="liquid-panel rounded-[24px] p-5">
                                <p className="flex items-center gap-2 text-sm font-semibold">
                                    <ShieldCheck className="text-cyan size-5" />{' '}
                                    Belum ada biaya otomatis
                                </p>
                                <p className="text-muted-foreground mt-2 text-xs leading-5">
                                    Tim akan memeriksa kebutuhan, menyusun
                                    estimasi, lalu meminta persetujuan sebelum
                                    pekerjaan berbayar dilakukan.
                                </p>
                            </div>
                        </aside>
                    </form>
                </div>
            </main>
            <AdComAssistant />
        </PublicShell>
    );
}

function Field({
    label,
    hint,
    error,
    required,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <label className="text-foreground block text-sm font-semibold">
            {label} {required && <span className="text-red-500">*</span>}{' '}
            {hint && (
                <span className="text-muted-foreground font-medium">
                    ({hint})
                </span>
            )}
            {children}
            <InputError message={error} className="mt-2" />
        </label>
    );
}

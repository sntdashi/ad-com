import { Head, Link } from '@inertiajs/react';
import {
    motion,
    useReducedMotion,
    useScroll,
    useTransform,
} from 'motion/react';
import { ArrowRight, Camera, Computer, Router, Wrench } from 'lucide-react';
import { useRef } from 'react';
import AdComAssistant from '@/components/adcom-assistant';
import { GlassButton } from '@/components/glass/GlassButton';
import { HeroNetworkEmblem3D } from '@/components/motion/HeroNetworkEmblem3D';
import {
    MotionStagger,
    MotionStaggerItem,
} from '@/components/motion/MotionStagger';
import { TiltSurface } from '@/components/motion/TiltSurface';
import PublicShell from '@/components/public-shell';
import { Button } from '@/components/ui/button';
import { VARIANTS } from '@/lib/motion';
import { create as createBooking } from '@/routes/booking';
import { index as trackIndex } from '@/routes/track';

const services = [
    {
        title: 'Jaringan & Wi-Fi',
        description:
            'Koneksi putus-putus, area tanpa sinyal, atau pemasangan jaringan baru.',
        icon: Router,
        slug: 'jaringan-wifi',
    },
    {
        title: 'Instalasi CCTV',
        description:
            'Survei lokasi, pemasangan kamera, dan pengaturan rekaman.',
        icon: Camera,
        slug: 'instalasi-cctv',
    },
    {
        title: 'Servis Komputer',
        description:
            'Pemeriksaan kerusakan, perawatan, dan penggantian komponen.',
        icon: Computer,
        slug: 'servis-komputer',
    },
    {
        title: 'Konsultasi IT',
        description:
            'Diskusikan kebutuhan perangkat dan jaringan sebelum menentukan pilihan.',
        icon: Wrench,
        slug: 'konsultasi-it',
    },
];

const steps = [
    [
        'Ajukan kebutuhan',
        'Pilih layanan dan ceritakan kendalanya. Belum tahu penyebabnya juga tidak apa-apa.',
    ],
    [
        'Konfirmasi dengan tim',
        'Tim memeriksa permintaanmu, lalu mengonfirmasi biaya dan jadwal sebelum bekerja.',
    ],
    [
        'Ikuti pengerjaan',
        'Gunakan tiket untuk melihat status dan nama teknisi yang ditugaskan.',
    ],
    [
        'Periksa hasilnya',
        'Setelah selesai, lihat catatan pekerjaan dan beri penilaian melalui akunmu.',
    ],
];

const heroPrimaryWords = ['Perangkat', 'bermasalah?'];
const heroAccentWords = ['Mulai', 'dari', 'sini.'];

export default function Welcome() {
    const heroRef = useRef<HTMLElement>(null);
    const shouldReduceMotion = useReducedMotion();

    const { scrollYProgress } = useScroll({
        target: heroRef,
        offset: ['start start', 'end start'],
    });

    const heroY = useTransform(scrollYProgress, [0, 1], [0, 28]);
    const heroScale = useTransform(scrollYProgress, [0, 1], [1, 0.985]);
    const heroBackdropY = useTransform(scrollYProgress, [0, 1], [0, -32]);

    return (
        <PublicShell homeNavigation>
            <Head>
                <title>AD Com — Layanan TEFA TKJ</title>
                <meta
                    name="description"
                    content="Layanan jaringan, CCTV, servis komputer, dan konsultasi IT dari AD Com, portal layanan Teaching Factory TKJ untuk sekolah dan masyarakat."
                />
                <meta property="og:title" content="AD Com — Layanan TEFA TKJ" />
                <meta
                    property="og:description"
                    content="Ajukan kebutuhan teknis, konfirmasi dengan tim, dan ikuti pengerjaan melalui tiket layanan."
                />
            </Head>
            <main id="main-content" className="relative overflow-x-clip">
                <section
                    ref={heroRef}
                    className="relative px-4 pt-4 sm:px-6 sm:pt-6"
                >
                    <motion.div
                        aria-hidden="true"
                        style={
                            shouldReduceMotion
                                ? {
                                      backgroundImage:
                                          'radial-gradient(circle at 18% 24%, rgba(169,37,59,0.12), transparent 46%), radial-gradient(circle at 82% 28%, rgba(217,213,211,0.52), transparent 48%), radial-gradient(circle at 52% 84%, rgba(243,231,233,0.45), transparent 46%)',
                                  }
                                : {
                                      y: heroBackdropY,
                                      backgroundImage:
                                          'radial-gradient(circle at 18% 24%, rgba(169,37,59,0.12), transparent 46%), radial-gradient(circle at 82% 28%, rgba(217,213,211,0.52), transparent 48%), radial-gradient(circle at 52% 84%, rgba(243,231,233,0.45), transparent 46%)',
                                  }
                        }
                        className="welcome-hero-backdrop pointer-events-none absolute inset-x-0 -top-24 -z-10 h-[560px] will-change-transform"
                    />
                    <motion.div
                        style={
                            shouldReduceMotion
                                ? undefined
                                : { y: heroY, scale: heroScale }
                        }
                        className="liquid-panel mx-auto grid max-w-6xl gap-8 rounded-[28px] p-5 will-change-transform sm:gap-12 sm:rounded-[34px] sm:p-8 lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:gap-16 lg:p-12"
                    >
                        <div>
                            <p className="text-brand-deep dark:text-primary mb-5 text-sm font-semibold">
                                Layanan TEFA · Teknik Komputer & Jaringan
                            </p>
                            <motion.h1
                                variants={VARIANTS.wordContainer}
                                initial={shouldReduceMotion ? false : 'hidden'}
                                animate="visible"
                                className="text-foreground max-w-xl text-[clamp(2.5rem,5vw,4.25rem)] leading-[1.08] font-semibold tracking-[-0.045em]"
                                style={{ perspective: '600px' }}
                            >
                                {heroPrimaryWords.map((word) => (
                                    <motion.span
                                        key={word}
                                        variants={VARIANTS.wordItem}
                                        className="mr-[0.26em] inline-block"
                                    >
                                        {word}
                                    </motion.span>
                                ))}
                                <br />
                                <span className="text-brand-deep dark:text-primary">
                                    {heroAccentWords.map((word, idx) => (
                                        <motion.span
                                            key={word}
                                            variants={VARIANTS.wordItem}
                                            className={
                                                idx < heroAccentWords.length - 1
                                                    ? 'mr-[0.26em] inline-block'
                                                    : 'inline-block'
                                            }
                                        >
                                            {word}
                                        </motion.span>
                                    ))}
                                </span>
                            </motion.h1>
                            <p className="text-muted-foreground mt-6 max-w-lg text-base leading-7">
                                Wi-Fi di rumah putus-putus, komputer tidak
                                menyala, atau perlu pasang CCTV? Ceritakan
                                kebutuhanmu kepada tim AD Com.
                            </p>
                            <div className="mt-8 flex flex-wrap items-center gap-3">
                                <Button
                                    asChild
                                    size="lg"
                                    className="group rounded-full shadow-[0_10px_24px_rgba(169,37,59,0.24)] transition-transform duration-150 hover:-translate-y-0.5 active:scale-[0.97]"
                                >
                                    <a href="#layanan">
                                        Pilih layanan{' '}
                                        <ArrowRight
                                            className="transition-transform duration-200 group-hover:translate-x-1"
                                            aria-hidden="true"
                                        />
                                    </a>
                                </Button>
                                <GlassButton
                                    asChild
                                    variant="outline"
                                    size="lg"
                                >
                                    <Link href={trackIndex()}>Lacak tiket</Link>
                                </GlassButton>
                            </div>
                            <p className="text-muted-foreground mt-4 text-sm">
                                Bisa mengajukan layanan tanpa membuat akun.
                            </p>
                        </div>
                        <aside
                            aria-label="Tentang layanan AD Com"
                            className="liquid-panel overflow-hidden rounded-3xl"
                        >
                            <HeroNetworkEmblem3D />
                            <div className="space-y-5 p-6 sm:p-7">
                                <div>
                                    <p className="text-foreground text-lg font-semibold">
                                        Dari sekolah, untuk sekitar.
                                    </p>
                                    <p className="text-muted-foreground mt-2 text-sm leading-6">
                                        Layanan teknis untuk warga sekolah,
                                        rumah tangga, dan usaha di lingkungan
                                        sekitar.
                                    </p>
                                </div>
                                <dl className="divide-border divide-y text-sm">
                                    <div className="flex justify-between gap-4 py-3">
                                        <dt className="text-muted-foreground">
                                            Penanganan
                                        </dt>
                                        <dd className="text-right font-medium">
                                            Tim teknisi AD Com
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-4 pt-3">
                                        <dt className="text-muted-foreground">
                                            Biaya & jadwal
                                        </dt>
                                        <dd className="text-right font-medium">
                                            Dikonfirmasi terlebih dahulu
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </aside>
                    </motion.div>
                </section>

                <section id="layanan" className="scroll-mt-40 py-14 sm:py-20">
                    <motion.div
                        variants={VARIANTS.scrollSection}
                        initial={shouldReduceMotion ? false : 'hidden'}
                        whileInView="visible"
                        viewport={{ once: true, amount: 0.18 }}
                        className="mx-auto max-w-6xl px-5 sm:px-6 lg:px-8"
                    >
                        <div className="mb-8 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                            <h2 className="text-foreground text-3xl font-semibold tracking-[-0.03em]">
                                Mau dibantu apa?
                            </h2>
                            <p className="text-muted-foreground max-w-sm text-sm leading-6">
                                Pilih layanan yang paling sesuai. Detail
                                kebutuhan bisa kamu jelaskan di langkah
                                berikutnya.
                            </p>
                        </div>
                        <MotionStagger
                            inView
                            className="grid gap-4 md:grid-cols-2"
                        >
                            {services.map(
                                ({ title, description, icon: Icon, slug }) => (
                                    <MotionStaggerItem key={slug}>
                                        <TiltSurface className="rounded-2xl">
                                            <Link
                                                href={createBooking({
                                                    query: { service: slug },
                                                })}
                                                className="liquid-control glass-pointer-lit group bg-card/85 flex items-start gap-4 rounded-2xl p-5 transition-colors focus-visible:ring-2 focus-visible:ring-offset-4 focus-visible:outline-none sm:p-6"
                                                aria-label={`Pesan ${title}`}
                                            >
                                                <span className="bg-brand/10 text-brand-deep dark:bg-primary/15 dark:text-primary mt-0.5 grid size-11 shrink-0 place-items-center rounded-xl border border-black/5 transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:scale-105 dark:border-white/10">
                                                    <Icon
                                                        className="size-5"
                                                        strokeWidth={1.75}
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <h3 className="text-foreground text-lg font-semibold">
                                                        {title}
                                                    </h3>
                                                    <p className="text-muted-foreground mt-2 text-sm leading-6">
                                                        {description}
                                                    </p>
                                                </div>
                                                <ArrowRight
                                                    className="text-muted-foreground group-hover:text-brand-deep mt-1.5 size-4 shrink-0 transition-transform duration-200 group-hover:translate-x-1"
                                                    aria-hidden="true"
                                                />
                                            </Link>
                                        </TiltSurface>
                                    </MotionStaggerItem>
                                ),
                            )}
                        </MotionStagger>
                        <p className="text-muted-foreground mt-5 text-sm leading-6">
                            Harga dan ruang lingkup pekerjaan dikonfirmasi
                            setelah permintaan diperiksa.
                        </p>
                    </motion.div>
                </section>

                <section
                    id="cara-kerja"
                    className="border-border scroll-mt-40 border-y bg-[#E8E4E2]/65 py-14 backdrop-blur-md sm:py-20 dark:bg-white/[0.03]"
                >
                    <motion.div
                        variants={VARIANTS.scrollSection}
                        initial={shouldReduceMotion ? false : 'hidden'}
                        whileInView="visible"
                        viewport={{ once: true, amount: 0.2 }}
                        className="mx-auto max-w-6xl px-5 sm:px-6 lg:px-8"
                    >
                        <h2 className="text-foreground text-3xl font-semibold tracking-[-0.03em]">
                            Dari permintaan sampai selesai.
                        </h2>
                        <motion.ol
                            variants={VARIANTS.staggerContainer}
                            initial={shouldReduceMotion ? false : 'hidden'}
                            whileInView="visible"
                            viewport={{ once: true, amount: 0.2 }}
                            className="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            {steps.map(([title, description], index) => (
                                <motion.li
                                    key={title}
                                    variants={VARIANTS.staggerItem}
                                    className="border-border border-t pt-5"
                                >
                                    <span
                                        className="text-brand-deep dark:text-primary font-mono text-sm font-semibold"
                                        aria-hidden="true"
                                    >
                                        0{index + 1}
                                    </span>
                                    <h3 className="text-foreground mt-4 text-base font-semibold">
                                        {title}
                                    </h3>
                                    <p className="text-muted-foreground mt-2 text-sm leading-6">
                                        {description}
                                    </p>
                                </motion.li>
                            ))}
                        </motion.ol>
                    </motion.div>
                </section>

                <section
                    id="lacak"
                    className="border-border border-b py-10 sm:py-12"
                >
                    <motion.div
                        variants={VARIANTS.scrollSection}
                        initial={shouldReduceMotion ? false : 'hidden'}
                        whileInView="visible"
                        viewport={{ once: true, amount: 0.25 }}
                        className="mx-auto flex max-w-6xl flex-col justify-between gap-6 px-5 sm:flex-row sm:items-center sm:px-6 lg:px-8"
                    >
                        <div>
                            <h2 className="text-foreground text-xl font-semibold">
                                Sudah mengajukan layanan?
                            </h2>
                            <p className="text-muted-foreground mt-2 max-w-xl text-sm leading-6">
                                Siapkan nomor tiket dan kode akses yang kamu
                                terima saat membuat pesanan. Simpan kode itu
                                untuk dirimu sendiri.
                            </p>
                        </div>
                        <Button
                            asChild
                            variant="outline"
                            size="lg"
                            className="group shrink-0 self-start rounded-full sm:self-auto"
                        >
                            <Link href={trackIndex()}>
                                Buka pelacakan{' '}
                                <ArrowRight
                                    className="transition-transform duration-200 group-hover:translate-x-1"
                                    aria-hidden="true"
                                />
                            </Link>
                        </Button>
                    </motion.div>
                </section>

                <section id="tentang" className="scroll-mt-40 py-14 sm:py-20">
                    <motion.div
                        variants={VARIANTS.scrollSection}
                        initial={shouldReduceMotion ? false : 'hidden'}
                        whileInView="visible"
                        viewport={{ once: true, amount: 0.2 }}
                        className="mx-auto grid max-w-6xl gap-6 px-5 sm:px-6 md:grid-cols-[0.8fr_1.2fr] md:gap-16 lg:px-8"
                    >
                        <div>
                            <p className="text-brand-deep dark:text-primary text-sm font-semibold">
                                Tentang AD Com
                            </p>
                            <h2 className="text-foreground mt-3 text-3xl leading-tight font-semibold tracking-[-0.03em]">
                                Portal layanan
                                <br />
                                TEFA TKJ.
                            </h2>
                        </div>
                        <div className="text-muted-foreground space-y-4 text-base leading-7">
                            <p>
                                AD Com mendukung layanan Teaching Factory (TEFA)
                                bidang Teknik Komputer dan Jaringan. Portal ini
                                mempertemukan kebutuhan masyarakat dengan tim
                                teknisi yang ditunjuk sekolah.
                            </p>
                            <p>
                                Siswa, warga sekolah, dan masyarakat bisa
                                mengajukan layanan sebagai pelanggan. Penugasan
                                teknisi serta pengawasan pekerjaan dikelola oleh
                                tim AD Com.
                            </p>
                            <Link
                                href={createBooking()}
                                className="group text-brand-deep dark:text-primary inline-flex min-h-11 items-center gap-2 font-semibold underline-offset-4 hover:underline"
                            >
                                Ajukan kebutuhanmu{' '}
                                <ArrowRight
                                    className="size-4 transition-transform duration-200 group-hover:translate-x-1"
                                    aria-hidden="true"
                                />
                            </Link>
                        </div>
                    </motion.div>
                </section>
            </main>
            <AdComAssistant />
        </PublicShell>
    );
}

import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Home,
    LayoutDashboard,
    LogIn,
    ShieldCheck,
    TicketCheck,
    Wrench,
} from 'lucide-react';
import {
    motion,
    useMotionValueEvent,
    useReducedMotion,
    useScroll,
    useSpring,
    useTransform,
} from 'motion/react';
import { useEffect, useState, type ReactNode } from 'react';
import BrandMark from '@/components/brand-mark';
import { LiquidGlassNav } from '@/components/glass/LiquidGlassNav';
import { LiquidHomeIndicator } from '@/components/glass/LiquidHomeIndicator';
import { MotionPage } from '@/components/motion/MotionPage';
import { Button } from '@/components/ui/button';
import { SPRING } from '@/lib/motion';
import { cn } from '@/lib/utils';
import { dashboard, home, login, privacy, terms } from '@/routes';
import { create as createBooking } from '@/routes/booking';
import { index as trackIndex } from '@/routes/track';

const SECTION_LINKS = [
    { id: 'layanan', label: 'Layanan', href: '#layanan' },
    { id: 'cara-kerja', label: 'Cara pesan', href: '#cara-kerja' },
    { id: 'tentang', label: 'Tentang TEFA', href: '#tentang' },
] as const;

type SectionId = (typeof SECTION_LINKS)[number]['id'] | '';

export default function PublicShell({
    children,
    backHref = home.url(),
    backLabel = 'Beranda',
    homeNavigation = false,
}: {
    children: ReactNode;
    backHref?: string;
    backLabel?: string;
    homeNavigation?: boolean;
}) {
    const page = usePage();
    const { auth } = page.props;
    const currentUrl = page.url ?? '';
    const pathname = currentUrl.split('?')[0].split('#')[0] || '/';
    const trackUrl = trackIndex.url();
    const bookingUrl = createBooking.url();

    const isTrack = pathname.startsWith(trackUrl);
    const isBooking = pathname.startsWith(bookingUrl);
    const isHome = homeNavigation || pathname === home.url();

    const [activeSection, setActiveSection] = useState<SectionId>('');

    const shouldReduceMotion = useReducedMotion();
    const { scrollY } = useScroll();
    const rawScrollProgress = useTransform(scrollY, [0, 76], [0, 1]);
    const smoothScrollProgress = useSpring(rawScrollProgress, {
        stiffness: 280,
        damping: 32,
        mass: 0.75,
        restDelta: 0.001,
    });
    const scrollProgress = shouldReduceMotion
        ? rawScrollProgress
        : smoothScrollProgress;

    const navbarHeight = useTransform(scrollProgress, [0, 1], [80, 62]);
    const brandScale = useTransform(scrollProgress, [0, 1], [1, 0.9]);
    const ctaScale = useTransform(scrollProgress, [0, 1], [1, 0.94]);

    useMotionValueEvent(scrollY, 'change', (y) => {
        if (homeNavigation && y < 120 && !window.location.hash) {
            setActiveSection((prev) => (prev === '' ? prev : ''));
        }
    });

    useEffect(() => {
        if (!homeNavigation || typeof window === 'undefined') {
            return;
        }

        const syncFromHash = () => {
            const hash = window.location.hash.replace(/^#/, '');
            if (
                hash === 'layanan' ||
                hash === 'cara-kerja' ||
                hash === 'tentang'
            ) {
                setActiveSection(hash);
            } else {
                setActiveSection('');
            }
        };

        syncFromHash();
        window.addEventListener('hashchange', syncFromHash);
        window.addEventListener('popstate', syncFromHash);

        const elements = SECTION_LINKS.map((s) =>
            document.getElementById(s.id),
        ).filter((el): el is HTMLElement => el !== null);

        const io =
            typeof IntersectionObserver !== 'undefined' && elements.length > 0
                ? new IntersectionObserver(
                      (entries) => {
                          const visible = entries
                              .filter((entry) => entry.isIntersecting)
                              .sort(
                                  (a, b) =>
                                      b.intersectionRatio - a.intersectionRatio,
                              );
                          if (visible[0]?.target.id) {
                              setActiveSection(
                                  visible[0].target.id as SectionId,
                              );
                          }
                      },
                      {
                          rootMargin: '-22% 0px -55% 0px',
                          threshold: [0.15, 0.4, 0.7],
                      },
                  )
                : null;

        elements.forEach((el) => io?.observe(el));

        return () => {
            window.removeEventListener('hashchange', syncFromHash);
            window.removeEventListener('popstate', syncFromHash);
            io?.disconnect();
        };
    }, [homeNavigation]);

    const isServicesActive =
        isBooking || (isHome && activeSection === 'layanan');
    const isBerandaActive = isHome && !isServicesActive && !isTrack;

    const desktopNavItems = [
        {
            id: 'home',
            label: 'Beranda',
            href: home(),
            active: isBerandaActive,
        },
        {
            id: 'services',
            label: 'Pesan Layanan',
            href: isHome && !isBooking ? '#layanan' : createBooking(),
            active: isServicesActive,
        },
        {
            id: 'track',
            label: 'Lacak Tiket',
            href: trackIndex(),
            active: isTrack,
        },
    ];

    const mobileDockItems = [
        {
            id: 'home',
            label: 'Beranda',
            href: home(),
            active: isBerandaActive,
            icon: <Home className="size-4" aria-hidden="true" />,
        },
        {
            id: 'services',
            label: 'Layanan',
            href: isHome && !isBooking ? '#layanan' : createBooking(),
            active: isServicesActive,
            icon: <Wrench className="size-4" aria-hidden="true" />,
        },
        {
            id: 'track',
            label: 'Lacak',
            href: trackIndex(),
            active: isTrack,
            icon: <TicketCheck className="size-4" aria-hidden="true" />,
        },
        {
            id: 'account',
            label: auth.user ? 'Dashboard' : 'Masuk',
            href: auth.user ? dashboard() : login(),
            active: false,
            icon: auth.user ? (
                <LayoutDashboard className="size-4" aria-hidden="true" />
            ) : (
                <LogIn className="size-4" aria-hidden="true" />
            ),
        },
    ];

    return (
        <div className="bg-background text-foreground min-h-screen pb-24 md:pb-0">
            {homeNavigation && (
                <a
                    href="#main-content"
                    className="bg-primary text-primary-foreground sr-only z-[100] rounded-md px-4 py-2 focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
                >
                    Langsung ke konten
                </a>
            )}
            <header className="glass-toolbar sticky top-0 z-50 border-x-0 border-t-0">
                <motion.div
                    aria-hidden="true"
                    style={{ opacity: scrollProgress }}
                    className="pointer-events-none absolute inset-0 -z-10 border-b border-[#29272B]/14 bg-gradient-to-b from-[#FAF8F7]/92 via-[#F2F0EF]/88 to-[#EAE6E4]/84 shadow-[0_14px_34px_-10px_rgba(41,39,43,0.14),0_2px_8px_rgba(41,39,43,0.05),inset_0_1px_0_rgba(255,255,255,0.94),inset_0_-1px_0_rgba(41,39,43,0.06)] dark:border-white/15 dark:from-[#26242A]/90 dark:via-[#1E1C21]/86 dark:to-[#18161B]/84 dark:shadow-[0_16px_38px_-10px_rgba(0,0,0,0.48),inset_0_1px_0_rgba(255,255,255,0.16)]"
                />
                <motion.div
                    style={{ height: navbarHeight }}
                    className="mx-auto flex h-20 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8"
                >
                    <Link
                        href={home()}
                        className="group flex items-center rounded-xl transition-transform duration-150 active:scale-[0.98]"
                        aria-label="AD Com, beranda"
                    >
                        <motion.span
                            style={{ scale: brandScale }}
                            className="flex origin-left items-center gap-3 will-change-transform"
                        >
                            <span className="grid h-11 w-15 place-items-center p-1.5">
                                <BrandMark className="w-full" />
                            </span>
                            <span>
                                <span className="block text-base leading-none font-black tracking-[-0.04em]">
                                    AD COM
                                </span>
                                <span className="text-muted-foreground mt-1 block text-[10px] font-medium tracking-[0.08em] uppercase">
                                    TEFA TKJ
                                </span>
                            </span>
                        </motion.span>
                    </Link>

                    <div className="hidden md:block">
                        <LiquidGlassNav
                            id="public-desktop"
                            items={desktopNavItems}
                            scrollProgress={scrollProgress}
                            ariaLabel="Navigasi utama AD Com"
                        />
                    </div>

                    <nav
                        className="flex items-center gap-1.5 text-sm font-bold"
                        aria-label="Navigasi aksi"
                    >
                        {!homeNavigation && (
                            <Button
                                variant="ghost"
                                asChild
                                className="hidden rounded-full sm:inline-flex md:hidden"
                            >
                                <Link href={backHref}>
                                    <ArrowLeft /> {backLabel}
                                </Link>
                            </Button>
                        )}
                        <motion.span
                            style={{ scale: ctaScale }}
                            className="inline-flex origin-right will-change-transform"
                        >
                            <Button
                                asChild
                                className="rounded-full px-5 shadow-[0_6px_18px_rgba(169,37,59,0.22)] transition-all duration-150 hover:-translate-y-[0.5px] active:scale-[0.97]"
                            >
                                <Link href={auth.user ? dashboard() : login()}>
                                    {auth.user ? 'Dashboard' : 'Masuk'}{' '}
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </motion.span>
                    </nav>
                </motion.div>
                {homeNavigation && (
                    <nav
                        aria-label="Isi halaman"
                        className="border-border/80 border-t bg-[#FAF9F8]/45 backdrop-blur-md dark:bg-white/[0.02]"
                    >
                        <div className="mx-auto flex max-w-6xl items-center gap-2 overflow-x-auto px-4 py-1.5 text-xs sm:px-6 lg:px-8">
                            {SECTION_LINKS.map((section) => {
                                const active = activeSection === section.id;
                                return (
                                    <a
                                        key={section.id}
                                        href={section.href}
                                        aria-current={
                                            active ? 'location' : undefined
                                        }
                                        onClick={() =>
                                            setActiveSection(section.id)
                                        }
                                        className={cn(
                                            'relative isolate inline-flex h-8 shrink-0 items-center rounded-full px-3.5 font-semibold transition-[color,transform] duration-150 hover:-translate-y-[0.5px] focus-visible:ring-2 focus-visible:ring-[#A9253B]/50 focus-visible:outline-none active:scale-[0.97]',
                                            active
                                                ? 'text-brand-deep dark:text-primary font-bold'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {active && (
                                            <motion.span
                                                layoutId="public-subnav-pill"
                                                transition={SPRING.pill}
                                                className="pointer-events-none absolute inset-0 -z-10 rounded-full bg-white/90 shadow-[0_2px_8px_rgba(41,39,43,0.08),inset_0_1px_0_rgba(255,255,255,0.95)] ring-1 ring-[#29272B]/10 dark:bg-white/14 dark:ring-white/18"
                                            />
                                        )}
                                        <span>{section.label}</span>
                                    </a>
                                );
                            })}
                        </div>
                    </nav>
                )}
            </header>

            <MotionPage pageKey={pathname || 'home'}>{children}</MotionPage>

            <footer className="border-border bg-card border-t">
                <div className="text-muted-foreground mx-auto flex max-w-6xl flex-col gap-4 px-5 py-7 text-xs sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p>
                        © {new Date().getFullYear()} AD Com · Layanan TEFA TKJ
                    </p>
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
                        <Link
                            href={privacy()}
                            className="hover:text-foreground inline-flex min-h-11 items-center gap-2"
                        >
                            <ShieldCheck
                                className="size-4"
                                aria-hidden="true"
                            />
                            Privasi
                        </Link>
                        <Link
                            href={terms()}
                            className="hover:text-foreground inline-flex min-h-11 items-center"
                        >
                            Syarat layanan
                        </Link>
                    </div>
                </div>
            </footer>

            <LiquidHomeIndicator items={mobileDockItems} />
        </div>
    );
}

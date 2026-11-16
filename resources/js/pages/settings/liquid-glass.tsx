import { Head } from '@inertiajs/react';
import {
    Cpu,
    Home,
    Layers,
    LayoutDashboard,
    Sparkles,
    TicketCheck,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import BrandMark from '@/components/brand-mark';
import {
    GlassButton,
    GlassNavigation,
    GlassPanel,
    LiquidGlassLens,
    LiquidGlassNav,
    LiquidHomeIndicator,
    type GlassNavItem,
    type LiquidNavItem,
} from '@/components/glass';
import { getGlassCapabilities } from '@/lib/liquid-glass';
import { cn } from '@/lib/utils';

type StageTheme = 'light' | 'dark' | 'maroon';

const STAGE_STYLES: Record<StageTheme, string> = {
    light: 'bg-[#f4f5f8] text-zinc-900',
    dark: 'bg-[#111318] text-white',
    maroon: 'bg-[#5c0d1b] text-white',
};

const STAGE_BACKDROPS: Record<StageTheme, string> = {
    light: 'radial-gradient(circle at 15% 20%, rgba(189,41,57,0.32), transparent 45%), radial-gradient(circle at 82% 28%, rgba(6,182,212,0.34), transparent 42%), radial-gradient(circle at 50% 85%, rgba(132,204,22,0.28), transparent 45%), linear-gradient(to right, rgba(18,19,22,0.12) 1px, transparent 1px), linear-gradient(to bottom, rgba(18,19,22,0.12) 1px, transparent 1px)',
    dark: 'radial-gradient(circle at 18% 24%, rgba(217,56,74,0.45), transparent 46%), radial-gradient(circle at 80% 24%, rgba(34,211,238,0.32), transparent 44%), radial-gradient(circle at 48% 82%, rgba(163,230,53,0.22), transparent 45%), linear-gradient(to right, rgba(255,255,255,0.12) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.12) 1px, transparent 1px)',
    maroon: 'radial-gradient(circle at 20% 25%, rgba(255,95,120,0.45), transparent 44%), radial-gradient(circle at 84% 25%, rgba(255,204,112,0.34), transparent 44%), radial-gradient(circle at 50% 85%, rgba(6,182,212,0.28), transparent 48%), linear-gradient(to right, rgba(255,255,255,0.16) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.16) 1px, transparent 1px)',
};

export default function LiquidGlassPlayground() {
    const [stageTheme, setStageTheme] = useState<StageTheme>('maroon');
    const [activeNav, setActiveNav] = useState('home');
    const [activeSeg, setActiveSeg] = useState('optics');
    const [popoverOpen, setPopoverOpen] = useState(false);

    const caps = getGlassCapabilities();

    const navItems: LiquidNavItem[] = [
        {
            id: 'home',
            label: 'Beranda',
            href: '#home',
            active: activeNav === 'home',
        },
        {
            id: 'services',
            label: 'Pesan Layanan',
            href: '#services',
            active: activeNav === 'services',
        },
        {
            id: 'track',
            label: 'Lacak Tiket',
            href: '#track',
            active: activeNav === 'track',
        },
    ];

    const segItems: GlassNavItem[] = [
        { title: 'Optics', href: '#optics', active: activeSeg === 'optics' },
        { title: 'Motion', href: '#motion', active: activeSeg === 'motion' },
        {
            title: 'Dispersion',
            href: '#dispersion',
            active: activeSeg === 'dispersion',
        },
    ];

    const dockItems = [
        {
            id: 'home',
            label: 'Beranda',
            href: '#home',
            active: true,
            icon: <Home className="size-4" />,
        },
        {
            id: 'services',
            label: 'Layanan',
            href: '#services',
            active: false,
            icon: <Wrench className="size-4" />,
        },
        {
            id: 'track',
            label: 'Lacak',
            href: '#track',
            active: false,
            icon: <TicketCheck className="size-4" />,
        },
        {
            id: 'dash',
            label: 'Dashboard',
            href: '#dash',
            active: false,
            icon: <LayoutDashboard className="size-4" />,
        },
    ];

    return (
        <div className="space-y-6">
            <Head title="Liquid Glass Playground" />

            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 className="text-foreground text-xl font-black tracking-tight">
                        Liquid Glass Optical Validation Playground
                    </h2>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Verifikasi refraksi SVG <code>feDisplacementMap</code>,
                        lensa sferis, dan indikator navigasi elastis pada latar
                        terang, gelap, dan AD Com Maroon.
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    {(['light', 'dark', 'maroon'] as const).map((theme) => (
                        <button
                            key={theme}
                            type="button"
                            onClick={() => setStageTheme(theme)}
                            className={cn(
                                'rounded-full px-3.5 py-1.5 text-xs font-bold capitalize transition',
                                stageTheme === theme
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'bg-muted text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {theme === 'maroon' ? 'AD Com Maroon' : theme}
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-4">
                <div className="glass-surface rounded-2xl p-3.5 text-xs">
                    <p className="text-muted-foreground font-semibold">
                        Browser Engine
                    </p>
                    <p className="text-foreground mt-1 flex items-center gap-1.5 font-mono text-sm font-bold uppercase">
                        <Cpu className="text-primary size-4" /> {caps.engine}
                    </p>
                </div>
                <div className="glass-surface rounded-2xl p-3.5 text-xs">
                    <p className="text-muted-foreground font-semibold">
                        Backdrop Copy & Lens
                    </p>
                    <p className="text-foreground mt-1 font-bold">
                        SVG <code>filter: url()</code> (Aktif semua browser)
                    </p>
                </div>
                <div className="glass-surface rounded-2xl p-3.5 text-xs">
                    <p className="text-muted-foreground font-semibold">
                        Dynamic DOM Backdrop
                    </p>
                    <p className="text-foreground mt-1 font-bold">
                        {caps.supportsBackdropUrl
                            ? 'Chromium backdrop-filter: url()'
                            : 'Blur fallback (non-Chromium)'}
                    </p>
                </div>
                <div className="glass-surface rounded-2xl p-3.5 text-xs">
                    <p className="text-muted-foreground font-semibold">
                        Accessibility
                    </p>
                    <p className="text-foreground mt-1 font-bold">
                        Reduced Motion: {caps.reducedMotion ? 'On' : 'Off'}
                    </p>
                </div>
            </div>

            <section
                data-lg-backdrop=".playground-backdrop"
                className={cn(
                    'relative overflow-hidden rounded-[28px] border border-black/10 p-6 sm:p-10 dark:border-white/15',
                    STAGE_STYLES[stageTheme],
                )}
            >
                <div
                    aria-hidden="true"
                    className="playground-backdrop pointer-events-none absolute inset-0"
                    style={{
                        backgroundImage: STAGE_BACKDROPS[stageTheme],
                        backgroundSize:
                            '100% 100%, 100% 100%, 100% 100%, 32px 32px, 32px 32px',
                    }}
                />

                <div className="relative z-10 space-y-8">
                    <div className="space-y-2">
                        <p className="text-xs font-bold tracking-wider uppercase opacity-75">
                            1. Floating Liquid Glass Navbar (Glide + Velocity
                            Stretch + Text Refraction)
                        </p>
                        <LiquidGlassNav
                            items={navItems}
                            backdropSelector=".playground-backdrop"
                            onSelect={(item) => setActiveNav(item.id)}
                        />
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <div className="space-y-2">
                            <p className="text-xs font-bold tracking-wider uppercase opacity-75">
                                2. Interactive Circular Optical Lens (Drag /
                                Hover to Bend Text & Grid)
                            </p>
                            <LiquidGlassLens
                                width={156}
                                height={156}
                                restX={68}
                                restY={46}
                                className="rounded-3xl border border-white/25 bg-white/10"
                            >
                                <div
                                    className="flex h-64 flex-col items-center justify-center gap-3 p-6 text-center"
                                    style={{
                                        backgroundImage:
                                            STAGE_BACKDROPS[stageTheme],
                                        backgroundSize:
                                            '100% 100%, 100% 100%, 100% 100%, 24px 24px, 24px 24px',
                                    }}
                                >
                                    <BrandMark className="w-40" />
                                    <p className="max-w-xs text-sm font-bold">
                                        Gerakkan pointer atau sentuh area ini
                                        untuk melihat pembelokan grid & huruf.
                                    </p>
                                </div>
                            </LiquidGlassLens>
                        </div>

                        <div className="space-y-6">
                            <div className="space-y-2">
                                <p className="text-xs font-bold tracking-wider uppercase opacity-75">
                                    3. Optical Glass Panel
                                </p>
                                <GlassPanel
                                    backdropSelector=".playground-backdrop"
                                    tone={
                                        stageTheme === 'maroon'
                                            ? 'maroon'
                                            : 'default'
                                    }
                                    className="p-5"
                                >
                                    <div className="flex items-start gap-3">
                                        <Sparkles className="mt-0.5 size-5 shrink-0" />
                                        <div>
                                            <h3 className="text-base font-bold">
                                                Refractive Surface Panel
                                            </h3>
                                            <p className="mt-1 text-xs leading-5 opacity-85">
                                                Panel ini merefraksikan latar
                                                belakang melalui{' '}
                                                <code>feDisplacementMap</code>{' '}
                                                dengan tepi melengkung dan
                                                specular highlight.
                                            </p>
                                        </div>
                                    </div>
                                </GlassPanel>
                            </div>

                            <div className="space-y-2">
                                <p className="text-xs font-bold tracking-wider uppercase opacity-75">
                                    4. Segmented Control & Glass Buttons
                                </p>
                                <div className="flex flex-wrap items-center gap-3">
                                    <GlassNavigation
                                        items={segItems}
                                        onSelect={(item) =>
                                            setActiveSeg(
                                                item.title.toLowerCase(),
                                            )
                                        }
                                    />
                                    <GlassButton
                                        backdropSelector=".playground-backdrop"
                                        onClick={() =>
                                            setPopoverOpen((prev) => !prev)
                                        }
                                    >
                                        <Layers className="size-4" /> Toggle
                                        Glass Popover
                                    </GlassButton>
                                </div>

                                {popoverOpen && (
                                    <div className="glass-popover mt-3 max-w-sm rounded-2xl p-4 text-xs">
                                        <p className="font-bold">
                                            Glass Popover Active
                                        </p>
                                        <p className="mt-1 opacity-80">
                                            Kontrol kontekstual dengan
                                            translucent surface & shadow depth.
                                        </p>
                                    </div>
                                )}
                            </div>

                            <div className="space-y-2">
                                <p className="text-xs font-bold tracking-wider uppercase opacity-75">
                                    5. Mobile Floating Dock + Dome Lens
                                </p>
                                <LiquidHomeIndicator
                                    inlinePreview
                                    backdropSelector=".playground-backdrop"
                                    items={dockItems}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    );
}

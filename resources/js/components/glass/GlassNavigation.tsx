import { Link, type InertiaLinkProps } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, type ReactNode } from 'react';
import { createSpring } from '@/lib/liquid-glass';
import { cn } from '@/lib/utils';

export type GlassNavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    active?: boolean;
    icon?: ReactNode;
    badge?: string | number;
};

type GlassNavigationProps = {
    items: GlassNavItem[];
    className?: string;
    ariaLabel?: string;
    onSelect?: (item: GlassNavItem, index: number) => void;
};

/**
 * Segmented Liquid Glass Control.
 *
 * Uses one shared spring-driven glass capsule indicator with velocity-reactive
 * internal specular highlight behind single-layered labels.
 */
export function GlassNavigation({
    items,
    className,
    ariaLabel = 'Navigasi seksi',
    onSelect,
}: GlassNavigationProps) {
    const navRef = useRef<HTMLElement>(null);
    const indicatorRef = useRef<HTMLSpanElement>(null);
    const highlightRef = useRef<HTMLSpanElement>(null);
    const linkRefs = useRef<Array<HTMLAnchorElement | null>>([]);
    const activeIdxRef = useRef(-1);
    const moveToRef = useRef<
        ((idx: number, immediate?: boolean) => void) | null
    >(null);
    const pressRef = useRef<((pressed: boolean) => void) | null>(null);

    const activeIdx = items.findIndex((item) => item.active);
    activeIdxRef.current = activeIdx;

    useLayoutEffect(() => {
        const nav = navRef.current;
        const indicator = indicatorRef.current;
        const highlight = highlightRef.current;
        if (!nav || !indicator) {
            return;
        }

        let x = 0;
        let y = 0;
        let w = 0;
        let h = 0;
        let vx = 0;
        let press = 0;
        let targetIdx = activeIdxRef.current;

        const render = () => {
            if (w < 1 || h < 1) {
                indicator.style.opacity = '0';
                return;
            }
            const stretch = Math.min(0.06, Math.abs(vx) * 0.00011);
            const pressScale = 1 - press * 0.03;
            const scaleX = (1 + stretch) * pressScale;
            const scaleY = (1 - stretch * 0.5) * pressScale;

            indicator.style.width = `${w}px`;
            indicator.style.height = `${h}px`;
            indicator.style.transform = `translate3d(${x}px, ${y}px, 0) scale(${scaleX}, ${scaleY})`;
            indicator.style.opacity = '1';

            if (highlight) {
                const shift = Math.max(-20, Math.min(20, vx * 0.014));
                highlight.style.background = `radial-gradient(80% 90% at ${50 + shift}% 15%, rgba(255, 255, 255, 0.9), rgba(243, 231, 233, 0.25) 52%, transparent 80%)`;
            }
        };

        const xs = createSpring(
            0,
            (val, vel) => {
                x = val;
                vx = vel;
                render();
            },
            { stiffness: 400, damping: 31, mass: 0.85 },
        );
        const ys = createSpring(
            0,
            (val) => {
                y = val;
                render();
            },
            { stiffness: 400, damping: 31, mass: 0.85 },
        );
        const ws = createSpring(
            0,
            (val) => {
                w = val;
                render();
            },
            { stiffness: 400, damping: 31, mass: 0.85 },
        );
        const hs = createSpring(
            0,
            (val) => {
                h = val;
                render();
            },
            { stiffness: 400, damping: 31, mass: 0.85 },
        );
        const ps = createSpring(
            0,
            (val) => {
                press = val;
                render();
            },
            { stiffness: 620, damping: 36 },
        );

        const moveTo = (idx: number, immediate = false) => {
            targetIdx = idx;
            const el = idx >= 0 ? linkRefs.current[idx] : undefined;
            if (!el) {
                w = 0;
                h = 0;
                indicator.style.opacity = '0';
                return;
            }
            xs.set(el.offsetLeft, { immediate });
            ys.set(el.offsetTop, { immediate });
            ws.set(el.offsetWidth, { immediate });
            hs.set(el.offsetHeight, { immediate });
        };

        moveToRef.current = moveTo;
        pressRef.current = (pressed: boolean) => ps.set(pressed ? 1 : 0);

        const onNavLeave = () => {
            ps.set(0);
            moveTo(activeIdxRef.current);
        };
        const onNavFocusOut = (e: FocusEvent) => {
            if (!nav.contains(e.relatedTarget as Node | null)) {
                moveTo(activeIdxRef.current);
            }
        };

        nav.addEventListener('pointerleave', onNavLeave);
        nav.addEventListener('focusout', onNavFocusOut);

        const ro =
            typeof ResizeObserver !== 'undefined'
                ? new ResizeObserver(() => moveTo(targetIdx, true))
                : null;
        ro?.observe(nav);
        linkRefs.current.forEach((el) => {
            if (el) {
                ro?.observe(el);
            }
        });
        void document.fonts?.ready.then(() => {
            moveTo(targetIdx, true);
        });
        moveTo(activeIdxRef.current, true);

        return () => {
            moveToRef.current = null;
            pressRef.current = null;
            nav.removeEventListener('pointerleave', onNavLeave);
            nav.removeEventListener('focusout', onNavFocusOut);
            ro?.disconnect();
            xs.stop();
            ys.stop();
            ws.stop();
            hs.stop();
            ps.stop();
        };
    }, []);

    useEffect(() => {
        moveToRef.current?.(activeIdx);
    }, [activeIdx, items.length]);

    return (
        <nav
            ref={navRef}
            aria-label={ariaLabel}
            className={cn(
                'glass-surface relative isolate inline-flex max-w-full flex-wrap items-center gap-1 overflow-hidden rounded-2xl p-1.5',
                className,
            )}
        >
            <span
                ref={indicatorRef}
                aria-hidden="true"
                className="pointer-events-none absolute top-0 left-0 z-10 origin-center overflow-hidden rounded-xl bg-gradient-to-b from-white/96 via-[#FAF9F8]/90 to-[#EAE6E4]/84 opacity-0 shadow-[0_3px_12px_rgba(41,39,43,0.09),inset_0_1px_0.5px_rgba(255,255,255,0.98),inset_0_-1px_1px_rgba(41,39,43,0.06)] ring-1 ring-[#29272B]/10 backdrop-blur-md dark:from-[#9d1b31]/72 dark:via-[#7b1527]/62 dark:to-[#5e101d]/72 dark:shadow-[0_4px_14px_rgba(0,0,0,0.48),inset_0_1px_0.5px_rgba(255,255,255,0.22)] dark:ring-[#fb7185]/35"
            >
                <span
                    ref={highlightRef}
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 rounded-xl"
                />
            </span>

            {items.map((item, idx) => (
                <Link
                    key={`${item.title}-${idx}`}
                    ref={(el) => {
                        linkRefs.current[idx] = el as HTMLAnchorElement | null;
                    }}
                    href={item.href}
                    prefetch
                    aria-current={item.active ? 'page' : undefined}
                    onPointerEnter={() => moveToRef.current?.(idx)}
                    onFocus={() => moveToRef.current?.(idx)}
                    onPointerDown={() => pressRef.current?.(true)}
                    onPointerUp={() => pressRef.current?.(false)}
                    onPointerCancel={() => pressRef.current?.(false)}
                    onClick={(event) => {
                        pressRef.current?.(false);
                        moveToRef.current?.(idx);
                        if (onSelect) {
                            event.preventDefault();
                            onSelect(item, idx);
                        }
                    }}
                    className={cn(
                        'group/seg-item relative z-20 inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-bold transition-[color,transform] duration-150 hover:-translate-y-[0.5px] focus-visible:ring-2 focus-visible:ring-[#A9253B]/50 focus-visible:outline-none active:scale-[0.97]',
                        item.active
                            ? 'text-[var(--brand-text)] dark:text-white'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {item.icon && (
                        <span className="inline-flex shrink-0 transition-transform duration-150 group-hover/seg-item:scale-110">
                            {item.icon}
                        </span>
                    )}
                    <span>{item.title}</span>
                    {item.badge !== undefined && (
                        <span className="rounded-full bg-black/[0.06] px-1.5 py-0.5 text-[10px] leading-none font-black dark:bg-white/[0.1]">
                            {item.badge}
                        </span>
                    )}
                </Link>
            ))}
        </nav>
    );
}

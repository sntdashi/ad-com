import { Link, type InertiaLinkProps } from '@inertiajs/react';
import {
    motion,
    useMotionValue,
    useTransform,
    type MotionValue,
} from 'motion/react';
import { useEffect, useLayoutEffect, useRef, type ReactNode } from 'react';
import { createBackdropSurface, createSpring } from '@/lib/liquid-glass';
import { cn } from '@/lib/utils';

export type LiquidNavItem = {
    id: string;
    label: string;
    href: NonNullable<InertiaLinkProps['href']>;
    active?: boolean;
    icon?: ReactNode;
};

type LiquidGlassNavProps = {
    items: LiquidNavItem[];
    id?: string;
    className?: string;
    ariaLabel?: string;
    backdropSelector?: string;
    compact?: boolean;
    scrollProgress?: MotionValue<number>;
    onSelect?: (item: LiquidNavItem) => void;
};

/**
 * Floating Liquid Glass Navigation Capsule (macOS Tahoe + iOS 26 inspired).
 *
 * Features:
 * - Physical optical glass capsule with warm pearl tint, specular rim, and pointer reflection.
 * - Continuous scroll-linked GPU scale and optical rim evolution without layout reflow.
 * - One shared spring-driven active indicator behind crisp, single-layered labels.
 * - Velocity-based elastic stretching, traveling internal highlight, and tactile press compression.
 */
export function LiquidGlassNav({
    items,
    className,
    ariaLabel = 'Navigasi utama',
    backdropSelector,
    compact = false,
    scrollProgress,
    onSelect,
}: LiquidGlassNavProps) {
    const navRef = useRef<HTMLElement>(null);
    const indicatorRef = useRef<HTMLSpanElement>(null);
    const highlightRef = useRef<HTMLSpanElement>(null);
    const linkRefs = useRef<Array<HTMLAnchorElement | null>>([]);
    const activeIdxRef = useRef(0);
    const moveToRef = useRef<
        ((idx: number, immediate?: boolean) => void) | null
    >(null);
    const pressRef = useRef<((pressed: boolean) => void) | null>(null);

    const fallbackProgress = useMotionValue(compact ? 1 : 0);
    const effectiveProgress = scrollProgress ?? fallbackProgress;
    const capsuleScale = useTransform(effectiveProgress, [0, 1], [1, 0.945]);
    const capsuleY = useTransform(effectiveProgress, [0, 1], [0, -0.5]);

    useEffect(() => {
        if (!scrollProgress) {
            fallbackProgress.set(compact ? 1 : 0);
        }
    }, [compact, fallbackProgress, scrollProgress]);

    const activeIdx = Math.max(
        0,
        items.findIndex((item) => item.active),
    );
    activeIdxRef.current = activeIdx;

    useLayoutEffect(() => {
        const nav = navRef.current;
        const indicator = indicatorRef.current;
        const highlight = highlightRef.current;
        if (!nav || !indicator) {
            return;
        }

        const reducedMotionQuery =
            typeof window !== 'undefined' && window.matchMedia
                ? window.matchMedia('(prefers-reduced-motion: reduce)')
                : null;

        const surface = createBackdropSurface(nav, {
            radius: Infinity,
            backdropSelector,
            optics: {
                refraction: 16,
                bezel: 0.78,
                curvature: 3,
                chroma: 0.1,
                blur: 1,
                specular: 0.58,
                specularWidth: 1.5,
            },
        });

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
            const reduceMotion = Boolean(reducedMotionQuery?.matches);
            const stretch = reduceMotion
                ? 0
                : Math.min(0.068, Math.abs(vx) * 0.00012);
            const pressScale = reduceMotion ? 1 : 1 - press * 0.032;
            const scaleX = (1 + stretch) * pressScale;
            const scaleY = (1 - stretch * 0.52) * pressScale;

            indicator.style.width = `${w}px`;
            indicator.style.height = `${h}px`;
            indicator.style.transform = `translate3d(${x}px, ${y}px, 0) scale(${scaleX}, ${scaleY})`;
            indicator.style.opacity = '1';

            if (highlight) {
                const shift = reduceMotion
                    ? 0
                    : Math.max(-22, Math.min(22, vx * 0.015));
                highlight.style.background = `radial-gradient(80% 90% at ${50 + shift}% 15%, rgba(255, 255, 255, 0.92), rgba(243, 231, 233, 0.28) 52%, transparent 80%)`;
            }
        };

        const springConfig = { stiffness: 410, damping: 32, mass: 0.85 };

        const xs = createSpring(
            0,
            (val, vel) => {
                x = val;
                vx = vel;
                render();
            },
            springConfig,
        );
        const ys = createSpring(
            0,
            (val) => {
                y = val;
                render();
            },
            springConfig,
        );
        const ws = createSpring(
            0,
            (val) => {
                w = val;
                render();
            },
            springConfig,
        );
        const hs = createSpring(
            0,
            (val) => {
                h = val;
                render();
            },
            springConfig,
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
            const el = linkRefs.current[idx];
            if (!el) {
                return;
            }
            targetIdx = idx;
            const snap = immediate || Boolean(reducedMotionQuery?.matches);
            xs.set(el.offsetLeft, { immediate: snap });
            ys.set(el.offsetTop, { immediate: snap });
            ws.set(el.offsetWidth, { immediate: snap });
            hs.set(el.offsetHeight, { immediate: snap });
        };

        moveToRef.current = moveTo;
        pressRef.current = (pressed: boolean) => {
            if (reducedMotionQuery?.matches) {
                return;
            }
            ps.set(pressed ? 1 : 0);
        };

        const onPointerMove = (e: PointerEvent) => {
            if (e.pointerType === 'touch') {
                return;
            }
            const rect = nav.getBoundingClientRect();
            if (rect.width > 0 && rect.height > 0) {
                nav.style.setProperty(
                    '--lg-x',
                    `${Math.round(((e.clientX - rect.left) / rect.width) * 100)}%`,
                );
                nav.style.setProperty(
                    '--lg-y',
                    `${Math.round(((e.clientY - rect.top) / rect.height) * 100)}%`,
                );
                nav.style.setProperty('--lg-active', '1');
            }
        };

        const onNavLeave = () => {
            nav.style.setProperty('--lg-active', '0');
            ps.set(0);
            moveTo(activeIdxRef.current);
        };

        const onNavFocusOut = (e: FocusEvent) => {
            if (!nav.contains(e.relatedTarget as Node | null)) {
                moveTo(activeIdxRef.current);
            }
        };

        nav.addEventListener('pointermove', onPointerMove, { passive: true });
        nav.addEventListener('pointerleave', onNavLeave);
        nav.addEventListener('focusout', onNavFocusOut);

        const ro =
            typeof ResizeObserver !== 'undefined'
                ? new ResizeObserver(() => {
                      const el = linkRefs.current[targetIdx];
                      if (!el) {
                          return;
                      }
                      if (
                          Math.abs(el.offsetLeft - xs.target) > 0.5 ||
                          Math.abs(el.offsetTop - ys.target) > 0.5 ||
                          Math.abs(el.offsetWidth - ws.target) > 0.5 ||
                          Math.abs(el.offsetHeight - hs.target) > 0.5
                      ) {
                          moveTo(targetIdx, true);
                      }
                  })
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
            nav.removeEventListener('pointermove', onPointerMove);
            nav.removeEventListener('pointerleave', onNavLeave);
            nav.removeEventListener('focusout', onNavFocusOut);
            ro?.disconnect();
            xs.stop();
            ys.stop();
            ws.stop();
            hs.stop();
            ps.stop();
            surface.destroy();
        };
    }, [backdropSelector]);

    useEffect(() => {
        moveToRef.current?.(activeIdx);
    }, [activeIdx, items.length]);

    return (
        <motion.nav
            ref={navRef}
            aria-label={ariaLabel}
            style={{ scale: capsuleScale, y: capsuleY }}
            className={cn(
                'glass-pointer-lit relative isolate inline-flex origin-center items-center gap-1 overflow-hidden rounded-full border border-[#29272B]/12 bg-gradient-to-b from-[#FAF8F7]/80 via-[#F2F0EF]/65 to-[#E6E2E0]/70 p-1.5 shadow-[0_10px_28px_-6px_rgba(41,39,43,0.12),0_2px_6px_rgba(41,39,43,0.05),inset_0_1px_0.5px_rgba(255,255,255,0.95),inset_0_-1px_1px_rgba(41,39,43,0.06)] will-change-transform dark:border-white/16 dark:from-[#2E2C32]/82 dark:via-[#242227]/72 dark:to-[#1C1A1F]/78 dark:shadow-[0_12px_32px_rgba(0,0,0,0.42),inset_0_1px_0.5px_rgba(255,255,255,0.22)]',
                className,
            )}
        >
            <motion.span
                aria-hidden="true"
                style={{ opacity: effectiveProgress }}
                className="pointer-events-none absolute inset-0 z-0 rounded-full bg-gradient-to-b from-white/36 via-[#FAF8F7]/18 to-[#D9D5D3]/22 shadow-[inset_0_1px_1px_rgba(255,255,255,0.98),inset_0_-1px_1px_rgba(169,37,59,0.08)] ring-1 ring-[#29272B]/8 dark:from-white/10 dark:via-transparent dark:to-black/22 dark:ring-white/12"
            />
            <span
                ref={indicatorRef}
                aria-hidden="true"
                className="pointer-events-none absolute top-0 left-0 z-10 origin-center overflow-hidden rounded-full bg-gradient-to-b from-white/96 via-[#FAF9F8]/90 to-[#EAE6E4]/84 opacity-0 shadow-[0_4px_12px_rgba(169,37,59,0.09),0_2px_6px_rgba(41,39,43,0.09),inset_0_1px_0.5px_rgba(255,255,255,0.98),inset_0_-1px_1px_rgba(41,39,43,0.06)] ring-1 ring-[#29272B]/11 backdrop-blur-md will-change-transform dark:from-[#9d1b31]/72 dark:via-[#7b1527]/62 dark:to-[#5e101d]/72 dark:shadow-[0_4px_14px_rgba(0,0,0,0.48),inset_0_1px_0.5px_rgba(255,255,255,0.22)] dark:ring-[#fb7185]/35"
            >
                <span
                    ref={highlightRef}
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 rounded-full"
                />
            </span>

            {items.map((item, idx) => {
                const isHashAnchor =
                    typeof item.href === 'string' && item.href.startsWith('#');
                const itemClassName = cn(
                    'group/nav-item relative z-20 inline-flex h-9 items-center gap-2 rounded-full px-4 text-xs font-semibold whitespace-nowrap transition-[color,transform] duration-150 hover:-translate-y-[0.5px] focus-visible:ring-2 focus-visible:ring-[#A9253B]/50 focus-visible:outline-none active:scale-[0.97]',
                    item.active
                        ? 'font-bold text-[var(--brand-text)] dark:text-white'
                        : 'text-[#29272B]/78 hover:text-[#29272B] dark:text-[#F2F0EF]/78 dark:hover:text-[#F2F0EF]',
                );
                const content = (
                    <>
                        {item.icon && (
                            <span
                                className={cn(
                                    'inline-flex shrink-0 transition-transform duration-150 group-hover/nav-item:-translate-y-[0.5px] group-hover/nav-item:scale-110',
                                    item.active &&
                                        'text-brand dark:text-[#ffd5dc]',
                                )}
                            >
                                {item.icon}
                            </span>
                        )}
                        <span>{item.label}</span>
                    </>
                );

                if (isHashAnchor) {
                    return (
                        <a
                            key={item.id}
                            ref={(el) => {
                                linkRefs.current[idx] = el;
                            }}
                            href={item.href as string}
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
                                    onSelect(item);
                                }
                            }}
                            className={itemClassName}
                        >
                            {content}
                        </a>
                    );
                }

                return (
                    <Link
                        key={item.id}
                        ref={(el) => {
                            linkRefs.current[idx] =
                                el as HTMLAnchorElement | null;
                        }}
                        href={item.href}
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
                                onSelect(item);
                            }
                        }}
                        className={itemClassName}
                    >
                        {content}
                    </Link>
                );
            })}
        </motion.nav>
    );
}

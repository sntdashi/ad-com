import { Link, type InertiaLinkProps } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, type ReactNode } from 'react';
import { createBackdropSurface, createSpring } from '@/lib/liquid-glass';
import { cn } from '@/lib/utils';

export type LiquidDockItem = {
    id: string;
    label: string;
    href: NonNullable<InertiaLinkProps['href']>;
    active?: boolean;
    icon: ReactNode;
};

type LiquidHomeIndicatorProps = {
    items: LiquidDockItem[];
    className?: string;
    backdropSelector?: string;
    /** When true, renders inline instead of fixed mobile-only bottom dock (used in Playground). */
    inlinePreview?: boolean;
};

/**
 * Mobile Floating Liquid Glass Dock.
 *
 * Uses one shared spring-animated glass capsule indicator with velocity-reactive
 * internal highlight and tactile press compression behind single-layered dock items.
 */
export function LiquidHomeIndicator({
    items,
    className,
    backdropSelector,
    inlinePreview = false,
}: LiquidHomeIndicatorProps) {
    const dockRef = useRef<HTMLDivElement>(null);
    const indicatorRef = useRef<HTMLSpanElement>(null);
    const highlightRef = useRef<HTMLSpanElement>(null);
    const linkRefs = useRef<Array<HTMLAnchorElement | null>>([]);
    const activeIdxRef = useRef(0);
    const moveToRef = useRef<
        ((idx: number, immediate?: boolean) => void) | null
    >(null);
    const pressRef = useRef<((pressed: boolean) => void) | null>(null);

    const activeIdx = Math.max(
        0,
        items.findIndex((item) => item.active),
    );
    activeIdxRef.current = activeIdx;

    useLayoutEffect(() => {
        const dock = dockRef.current;
        const indicator = indicatorRef.current;
        const highlight = highlightRef.current;
        if (!dock || !indicator) {
            return;
        }

        const reducedMotionQuery =
            typeof window !== 'undefined' && window.matchMedia
                ? window.matchMedia('(prefers-reduced-motion: reduce)')
                : null;

        const surface = createBackdropSurface(dock, {
            radius: 26,
            backdropSelector,
            optics: {
                refraction: 16,
                bezel: 0.6,
                curvature: 3,
                chroma: 0.1,
                blur: 1,
                specular: 0.55,
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
                : Math.min(0.06, Math.abs(vx) * 0.00012);
            const pressScale = reduceMotion ? 1 : 1 - press * 0.035;
            const scaleX = (1 + stretch) * pressScale;
            const scaleY = (1 - stretch * 0.5) * pressScale;

            indicator.style.width = `${w}px`;
            indicator.style.height = `${h}px`;
            indicator.style.transform = `translate3d(${x}px, ${y}px, 0) scale(${scaleX}, ${scaleY})`;
            indicator.style.opacity = '1';

            if (highlight) {
                const shift = reduceMotion
                    ? 0
                    : Math.max(-20, Math.min(20, vx * 0.014));
                highlight.style.background = `radial-gradient(80% 90% at ${50 + shift}% 15%, rgba(255, 255, 255, 0.92), rgba(243, 231, 233, 0.25) 52%, transparent 80%)`;
            }
        };

        const springConfig = { stiffness: 420, damping: 32, mass: 0.85 };

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

        const onLeave = () => {
            ps.set(0);
            moveTo(activeIdxRef.current);
        };
        const onFocusOut = (e: FocusEvent) => {
            if (!dock.contains(e.relatedTarget as Node | null)) {
                moveTo(activeIdxRef.current);
            }
        };

        dock.addEventListener('pointerleave', onLeave);
        dock.addEventListener('focusout', onFocusOut);

        const ro =
            typeof ResizeObserver !== 'undefined'
                ? new ResizeObserver(() => moveTo(targetIdx, true))
                : null;
        ro?.observe(dock);
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
            dock.removeEventListener('pointerleave', onLeave);
            dock.removeEventListener('focusout', onFocusOut);
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

    const dockContent = (
        <div
            ref={dockRef}
            className={cn(
                'relative isolate inline-flex max-w-[calc(100vw-1.5rem)] touch-manipulation items-center gap-1 overflow-hidden rounded-[26px] border border-[#29272B]/12 bg-gradient-to-b from-[#FAF8F7]/86 via-[#F2F0EF]/74 to-[#E6E2E0]/78 p-1.5 shadow-[0_14px_34px_-6px_rgba(41,39,43,0.18),0_2px_8px_rgba(41,39,43,0.08),inset_0_1px_0.5px_rgba(255,255,255,0.95)] dark:border-white/16 dark:from-[#2E2C32]/86 dark:via-[#242227]/78 dark:to-[#1C1A1F]/84 dark:shadow-[0_16px_38px_rgba(0,0,0,0.48),inset_0_1px_0.5px_rgba(255,255,255,0.22)]',
                !inlinePreview && 'pointer-events-auto',
                className,
            )}
        >
            <span
                ref={indicatorRef}
                aria-hidden="true"
                className="pointer-events-none absolute top-0 left-0 z-10 origin-center overflow-hidden rounded-[20px] bg-gradient-to-b from-white/96 via-[#FAF9F8]/90 to-[#EAE6E4]/84 opacity-0 shadow-[0_3px_12px_rgba(169,37,59,0.09),0_2px_6px_rgba(41,39,43,0.09),inset_0_1px_0.5px_rgba(255,255,255,0.98)] ring-1 ring-[#29272B]/11 backdrop-blur-md will-change-transform dark:from-white/22 dark:via-white/14 dark:to-white/10 dark:shadow-[0_4px_14px_rgba(0,0,0,0.42),inset_0_1px_0.5px_rgba(255,255,255,0.28)] dark:ring-white/22"
            >
                <span
                    ref={highlightRef}
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 rounded-[20px]"
                />
            </span>
            {items.map((item, idx) => {
                const isHashAnchor =
                    typeof item.href === 'string' && item.href.startsWith('#');
                const itemClassName = cn(
                    'group/dock-item relative z-20 flex min-w-[66px] touch-manipulation flex-col items-center justify-center gap-0.5 rounded-[20px] px-2.5 py-2 text-[10px] font-bold transition-[color,transform] duration-150 focus-visible:ring-2 focus-visible:ring-[#A9253B]/50 focus-visible:outline-none active:scale-[0.95]',
                    item.active
                        ? 'text-brand-deep dark:text-primary'
                        : 'text-[#29272B]/75 hover:text-[#29272B] dark:text-[#F2F0EF]/75 dark:hover:text-[#F2F0EF]',
                );
                const content = (
                    <>
                        <span className="grid size-5 place-items-center transition-transform duration-150 group-hover/dock-item:-translate-y-0.5 group-hover/dock-item:scale-110">
                            {item.icon}
                        </span>
                        <span className="leading-none">{item.label}</span>
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
                            onClick={() => {
                                pressRef.current?.(false);
                                moveToRef.current?.(idx);
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
                        onClick={() => {
                            pressRef.current?.(false);
                            moveToRef.current?.(idx);
                        }}
                        className={itemClassName}
                    >
                        {content}
                    </Link>
                );
            })}
        </div>
    );

    if (inlinePreview) {
        return dockContent;
    }

    return (
        <div
            aria-label="Navigasi cepat mobile"
            className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center px-3 pt-2 pb-[calc(0.55rem+env(safe-area-inset-bottom,0px))] md:hidden"
        >
            {dockContent}
            <span
                aria-hidden="true"
                className="mt-1.5 h-1 w-28 rounded-full bg-black/20 backdrop-blur-md dark:bg-white/25"
            />
        </div>
    );
}

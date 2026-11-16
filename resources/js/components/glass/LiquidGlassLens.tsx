import { useEffect, useRef, type ReactNode } from 'react';
import { createSpring, type GlassOptics } from '@/lib/liquid-glass';
import { cn } from '@/lib/utils';

export type LiquidGlassLensProps = {
    children: ReactNode;
    className?: string;
    width?: number;
    height?: number;
    radius?: number;
    /** Resting horizontal position in percent (0-100). */
    restX?: number;
    /** Resting vertical position in percent (0-100). */
    restY?: number;
    /** Hide lens when pointer is not interacting. */
    hiddenOnIdle?: boolean;
    optics?: Partial<GlassOptics>;
};

/**
 * Localized Interactive Optical Glass Lens.
 *
 * Renders a strictly clipped, pointer-tracking optical glass lens behind the
 * component's content using transformed-container local coordinate mapping.
 * Never distorts foreground logos/text or escapes its container boundary.
 */
export function LiquidGlassLens({
    children,
    className,
    width = 168,
    height = 168,
    radius,
    restX = 50,
    restY = 50,
    hiddenOnIdle = false,
}: LiquidGlassLensProps) {
    const hostRef = useRef<HTMLDivElement>(null);
    const lensRef = useRef<HTMLSpanElement>(null);

    useEffect(() => {
        const host = hostRef.current;
        const lens = lensRef.current;
        if (!host || !lens) {
            return;
        }

        const rCap = radius ?? Math.min(width, height) / 2;
        const pos = {
            x: (host.clientWidth * restX) / 100,
            y: (host.clientHeight * restY) / 100,
        };
        let press = 0;
        let active = hiddenOnIdle ? 0 : 1;

        const render = () => {
            const maxW = Math.max(0, host.clientWidth);
            const maxH = Math.max(0, host.clientHeight);
            if (maxW < 1 || maxH < 1 || (hiddenOnIdle && active < 0.02)) {
                lens.style.opacity = '0';
                return;
            }

            const w = Math.min(maxW, width + press * 14);
            const h = Math.min(maxH, height - press * 6);
            const r = Math.min(rCap, w / 2, h / 2);
            const x = Math.max(0, Math.min(maxW - w, pos.x - w / 2));
            const y = Math.max(0, Math.min(maxH - h, pos.y - h / 2));

            lens.style.width = `${w}px`;
            lens.style.height = `${h}px`;
            lens.style.borderRadius = `${r}px`;
            lens.style.transform = `translate3d(${x}px, ${y}px, 0)`;
            lens.style.opacity = String(active);
        };

        const xs = createSpring(
            pos.x,
            (v) => {
                pos.x = v;
                render();
            },
            { stiffness: 280, damping: 26 },
        );
        const ys = createSpring(
            pos.y,
            (v) => {
                pos.y = v;
                render();
            },
            { stiffness: 280, damping: 26 },
        );
        const ps = createSpring(
            0,
            (v) => {
                press = v;
                render();
            },
            { stiffness: 600, damping: 36 },
        );
        const as = createSpring(
            active,
            (v) => {
                active = v;
                render();
            },
            { stiffness: 420, damping: 32 },
        );

        const goHome = (immediate = false) => {
            xs.set((host.clientWidth * restX) / 100, { immediate });
            ys.set((host.clientHeight * restY) / 100, { immediate });
            if (hiddenOnIdle) {
                as.set(0, { immediate });
            }
        };

        const ro =
            typeof ResizeObserver !== 'undefined'
                ? new ResizeObserver(() => goHome(true))
                : null;
        ro?.observe(host);
        goHome(true);

        const toLocalCoords = (e: PointerEvent) => {
            const rect = host.getBoundingClientRect();
            const scaleX = rect.width > 0 ? host.clientWidth / rect.width : 1;
            const scaleY =
                rect.height > 0 ? host.clientHeight / rect.height : 1;

            return {
                x: (e.clientX - rect.left) * scaleX,
                y: (e.clientY - rect.top) * scaleY,
            };
        };

        const follow = (e: PointerEvent, immediate = false) => {
            const local = toLocalCoords(e);
            xs.set(local.x, { immediate });
            ys.set(local.y, { immediate });
            if (hiddenOnIdle) {
                as.set(1);
            }
        };

        const onEnter = (e: PointerEvent) => follow(e, false);
        const onMove = (e: PointerEvent) => follow(e, false);
        const onDown = (e: PointerEvent) => {
            ps.set(1);
            follow(e, false);
        };
        const onUp = () => ps.set(0);
        const onLeave = () => {
            ps.set(0);
            goHome(false);
        };

        host.addEventListener('pointerenter', onEnter, { passive: true });
        host.addEventListener('pointermove', onMove, { passive: true });
        host.addEventListener('pointerdown', onDown, { passive: true });
        host.addEventListener('pointerup', onUp, { passive: true });
        host.addEventListener('pointercancel', onLeave, { passive: true });
        host.addEventListener('pointerleave', onLeave, { passive: true });

        return () => {
            host.removeEventListener('pointerenter', onEnter);
            host.removeEventListener('pointermove', onMove);
            host.removeEventListener('pointerdown', onDown);
            host.removeEventListener('pointerup', onUp);
            host.removeEventListener('pointercancel', onLeave);
            host.removeEventListener('pointerleave', onLeave);
            ro?.disconnect();
            xs.stop();
            ys.stop();
            ps.stop();
            as.stop();
        };
    }, [width, height, radius, restX, restY, hiddenOnIdle]);

    return (
        <div
            ref={hostRef}
            className={cn(
                'relative isolate touch-pan-y overflow-hidden rounded-[inherit]',
                className,
            )}
        >
            <span
                ref={lensRef}
                aria-hidden="true"
                className="pointer-events-none absolute top-0 left-0 z-0 opacity-0 backdrop-blur-[3px] backdrop-contrast-[1.06] backdrop-saturate-150"
                style={{
                    background:
                        'radial-gradient(circle at 30% 26%, rgba(255,255,255,0.44) 0%, rgba(242,240,239,0.12) 44%, rgba(217,213,211,0.08) 72%, rgba(169,37,59,0.11) 100%)',
                    boxShadow:
                        '0 0 0 1px rgba(41,39,43,0.09), inset 0 1.5px 1.5px rgba(255,255,255,0.92), inset 1.5px 0 3px rgba(169,37,59,0.12), inset -1.5px 0 3px rgba(217,213,211,0.28), inset 0 -1.5px 2px rgba(41,39,43,0.08), 0 8px 22px rgba(41,39,43,0.1)',
                    border: '1px solid rgba(255,255,255,0.72)',
                }}
            />
            <div className="relative z-10 h-full w-full">{children}</div>
        </div>
    );
}

import { useEffect, useRef, type ReactNode } from 'react';
import { createBackdropSurface, type GlassOptics } from '@/lib/liquid-glass';
import { cn } from '@/lib/utils';

type GlassPanelProps = {
    children: ReactNode;
    className?: string;
    radius?: number;
    tone?: 'default' | 'strong' | 'maroon';
    backdropSelector?: string;
    optics?: Partial<GlassOptics>;
};

/**
 * Optical Liquid Glass Panel (adapted from liquidglass.dev <lg-panel>).
 * Refracts an aligned `[data-lg-backdrop]` copy on all browsers or live DOM in Chromium.
 */
export function GlassPanel({
    children,
    className,
    radius = 24,
    tone = 'default',
    backdropSelector,
    optics,
}: GlassPanelProps) {
    const hostRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const host = hostRef.current;
        if (!host) {
            return;
        }
        const tintClassName =
            tone === 'strong'
                ? 'lg-surface__tint--strong'
                : tone === 'maroon'
                  ? 'lg-surface__tint--maroon'
                  : undefined;

        const surface = createBackdropSurface(host, {
            radius,
            bleed: 6,
            backdropSelector,
            tintClassName,
            optics: {
                refraction: 24,
                bezel: 0.34,
                curvature: 4,
                chroma: 0.1,
                blur: 2,
                specular: 0.5,
                specularWidth: 2,
                ...optics,
            },
        });

        return () => {
            surface.destroy();
        };
    }, [radius, tone, backdropSelector, optics]);

    return (
        <div
            ref={hostRef}
            className={cn('relative isolate rounded-3xl', className)}
        >
            <div className="relative z-10">{children}</div>
        </div>
    );
}

import { useEffect, useRef, type ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import { usePointerLight } from '@/hooks/use-pointer-light';
import { createBackdropSurface, createSpring } from '@/lib/liquid-glass';
import { cn } from '@/lib/utils';

type GlassButtonProps = ComponentProps<typeof Button> & {
    glass?: boolean;
    backdropSelector?: string;
    tone?: 'default' | 'strong' | 'maroon';
};

export function GlassButton({
    className,
    glass = true,
    variant = 'outline',
    backdropSelector,
    tone = 'default',
    onPointerEnter,
    onPointerMove,
    onPointerLeave,
    onPointerDown,
    onPointerUp,
    onPointerCancel,
    ...props
}: GlassButtonProps) {
    const isGlassSurface =
        glass && variant !== 'default' && variant !== 'destructive';
    const hostRef = useRef<HTMLSpanElement>(null);
    const { ref: lightRef, pointerHandlers } =
        usePointerLight<HTMLButtonElement>();

    useEffect(() => {
        if (!isGlassSurface || !hostRef.current) {
            return;
        }
        const host = hostRef.current;
        const tintClassName =
            tone === 'strong'
                ? 'lg-surface__tint--strong'
                : tone === 'maroon'
                  ? 'lg-surface__tint--maroon'
                  : undefined;

        const surface = createBackdropSurface(host, {
            radius: Infinity,
            bleed: 12,
            backdropSelector,
            tintClassName,
            optics: {
                refraction: 14,
                bezel: 0.8,
                curvature: 3,
                chroma: 0.12,
                specular: 0.6,
                specularWidth: 1.5,
            },
        });

        const press = createSpring(
            0,
            (p) => {
                host.style.transform =
                    p > 0.001
                        ? `scaleX(${1 + 0.025 * p}) scaleY(${1 - 0.03 * p})`
                        : '';
            },
            { stiffness: 600, damping: 36 },
        );

        const onDown = () => press.set(1);
        const onRelease = () => press.set(0);

        host.addEventListener('pointerdown', onDown);
        host.addEventListener('pointerup', onRelease);
        host.addEventListener('pointercancel', onRelease);
        host.addEventListener('pointerleave', onRelease);

        return () => {
            host.removeEventListener('pointerdown', onDown);
            host.removeEventListener('pointerup', onRelease);
            host.removeEventListener('pointercancel', onRelease);
            host.removeEventListener('pointerleave', onRelease);
            press.stop();
            surface.destroy();
        };
    }, [isGlassSurface, backdropSelector, tone]);

    if (!isGlassSurface) {
        return (
            <Button
                variant={variant}
                className={cn(
                    variant === 'default' &&
                        'rounded-full shadow-[0_8px_22px_rgba(169,37,59,0.24)]',
                    'transition-transform duration-150 active:scale-[0.97]',
                    className,
                )}
                {...props}
            />
        );
    }

    return (
        <span
            ref={hostRef}
            className="relative isolate inline-flex overflow-hidden rounded-full align-middle will-change-transform"
        >
            <Button
                ref={lightRef}
                variant="ghost"
                onPointerEnter={(event) => {
                    pointerHandlers.onPointerEnter(event);
                    onPointerEnter?.(event);
                }}
                onPointerMove={(event) => {
                    pointerHandlers.onPointerMove(event);
                    onPointerMove?.(event);
                }}
                onPointerLeave={(event) => {
                    pointerHandlers.onPointerLeave();
                    onPointerLeave?.(event);
                }}
                onPointerDown={(event) => {
                    pointerHandlers.onPointerDown(event);
                    onPointerDown?.(event);
                }}
                onPointerUp={(event) => {
                    pointerHandlers.onPointerUp();
                    onPointerUp?.(event);
                }}
                onPointerCancel={(event) => {
                    pointerHandlers.onPointerCancel();
                    onPointerCancel?.(event);
                }}
                className={cn(
                    'glass-pointer-lit relative z-10 rounded-full border-0 bg-transparent shadow-none hover:bg-transparent',
                    className,
                )}
                {...props}
            />
        </span>
    );
}

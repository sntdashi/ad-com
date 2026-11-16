import {
    motion,
    useMotionTemplate,
    useMotionValue,
    useReducedMotion,
    useSpring,
} from 'motion/react';
import { useRef, type PointerEvent, type ReactNode } from 'react';
import { SPRING } from '@/lib/motion';
import { cn } from '@/lib/utils';

type TiltSurfaceProps = {
    children: ReactNode;
    className?: string;
    /** Maximum tilt angle in degrees (kept subtle for enterprise usability). */
    maxTilt?: number;
};

/**
 * Interactive 3D Perspective Surface.
 *
 * Applies controlled pointer-responsive 3D tilt, localized radial + rim light
 * reflections, and smooth hover/press spring elevation while respecting
 * `prefers-reduced-motion`.
 */
export function TiltSurface({
    children,
    className,
    maxTilt = 4.5,
}: TiltSurfaceProps) {
    const ref = useRef<HTMLDivElement>(null);
    const shouldReduceMotion = useReducedMotion();

    const rawRotateX = useMotionValue(0);
    const rawRotateY = useMotionValue(0);
    const rawScale = useMotionValue(1);
    const lightX = useMotionValue(50);
    const lightY = useMotionValue(50);
    const lightOpacity = useMotionValue(0);

    const rotateX = useSpring(rawRotateX, SPRING.tilt);
    const rotateY = useSpring(rawRotateY, SPRING.tilt);
    const scale = useSpring(rawScale, SPRING.snappy);
    const smoothLightOpacity = useSpring(lightOpacity, SPRING.gentle);

    const specularBg = useMotionTemplate`radial-gradient(240px circle at ${lightX}% ${lightY}%, rgba(255, 255, 255, 0.56), rgba(243, 231, 233, 0.18) 45%, transparent 74%)`;
    const rimBg = useMotionTemplate`radial-gradient(180px circle at ${lightX}% ${lightY}%, rgba(169, 37, 59, 0.12), transparent 70%)`;

    const handlePointerMove = (event: PointerEvent<HTMLDivElement>) => {
        if (shouldReduceMotion || event.pointerType === 'touch') {
            return;
        }
        const el = ref.current;
        if (!el) {
            return;
        }
        const rect = el.getBoundingClientRect();
        if (rect.width < 1 || rect.height < 1) {
            return;
        }

        const px = (event.clientX - rect.left) / rect.width;
        const py = (event.clientY - rect.top) / rect.height;

        rawRotateX.set((0.5 - py) * maxTilt * 2);
        rawRotateY.set((px - 0.5) * maxTilt * 2);
        lightX.set(Math.round(px * 100));
        lightY.set(Math.round(py * 100));
        lightOpacity.set(1);
    };

    const handlePointerEnter = (event: PointerEvent<HTMLDivElement>) => {
        if (shouldReduceMotion || event.pointerType === 'touch') {
            return;
        }
        rawScale.set(1.012);
        lightOpacity.set(1);
    };

    const handlePointerLeave = () => {
        rawRotateX.set(0);
        rawRotateY.set(0);
        rawScale.set(1);
        lightOpacity.set(0);
    };

    const handlePointerDown = () => {
        if (shouldReduceMotion) {
            return;
        }
        rawScale.set(0.988);
    };

    const handlePointerUp = (event: PointerEvent<HTMLDivElement>) => {
        if (shouldReduceMotion) {
            return;
        }
        rawScale.set(event.pointerType === 'touch' ? 1 : 1.012);
    };

    return (
        <div
            className="relative"
            style={{
                perspective: '1100px',
            }}
        >
            <motion.div
                ref={ref}
                onPointerMove={handlePointerMove}
                onPointerEnter={handlePointerEnter}
                onPointerLeave={handlePointerLeave}
                onPointerDown={handlePointerDown}
                onPointerUp={handlePointerUp}
                onPointerCancel={handlePointerLeave}
                style={
                    shouldReduceMotion
                        ? undefined
                        : {
                              rotateX,
                              rotateY,
                              scale,
                              transformStyle: 'preserve-3d',
                          }
                }
                className={cn(
                    'relative isolate overflow-hidden rounded-2xl will-change-transform',
                    className,
                )}
            >
                <motion.span
                    aria-hidden="true"
                    style={{
                        opacity: smoothLightOpacity,
                        background: specularBg,
                    }}
                    className="pointer-events-none absolute inset-0 z-10 rounded-[inherit]"
                />
                <motion.span
                    aria-hidden="true"
                    style={{
                        opacity: smoothLightOpacity,
                        background: rimBg,
                    }}
                    className="pointer-events-none absolute inset-0 z-10 rounded-[inherit]"
                />
                <div className="relative z-20">{children}</div>
            </motion.div>
        </div>
    );
}

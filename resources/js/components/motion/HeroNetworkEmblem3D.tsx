import {
    motion,
    useMotionTemplate,
    useMotionValue,
    useReducedMotion,
    useSpring,
    useTransform,
} from 'motion/react';
import { useRef, type PointerEvent } from 'react';
import BrandMark from '@/components/brand-mark';
import { SPRING } from '@/lib/motion';

/**
 * Interactive 3D Hardware & Network Emblem for the Hero Panel.
 *
 * Uses multi-plane CSS 3D depth (`preserve-3d` + `translate3d`), cast shadow
 * counter-parallax, extruded slab thickness, and pointer-tracked specular
 * reflections with natural spring physics (`SPRING.gentle`).
 */
export function HeroNetworkEmblem3D() {
    const containerRef = useRef<HTMLDivElement>(null);
    const shouldReduceMotion = useReducedMotion();

    const rawRotateX = useMotionValue(6);
    const rawRotateY = useMotionValue(-8);
    const glintX = useMotionValue(48);
    const glintY = useMotionValue(36);
    const emblemGlintX = useMotionValue(44);
    const emblemGlintY = useMotionValue(30);

    const rotateX = useSpring(rawRotateX, SPRING.gentle);
    const rotateY = useSpring(rawRotateY, SPRING.gentle);

    // Counter-parallax for cast shadow and floating layers to heighten spatial depth
    const shadowX = useTransform(rotateY, [-16, 16], [10, -10]);
    const shadowY = useTransform(rotateX, [-14, 14], [-8, 12]);
    const ringX = useTransform(rotateY, [-16, 16], [-3.5, 3.5]);
    const ringY = useTransform(rotateX, [-14, 14], [3, -3]);
    const badgeX = useTransform(rotateY, [-16, 16], [4.5, -4.5]);
    const badgeY = useTransform(rotateX, [-14, 14], [-3.5, 3.5]);

    const chamberGlintBg = useMotionTemplate`radial-gradient(240px circle at ${glintX}% ${glintY}%, rgba(255, 255, 255, 0.64), rgba(243, 231, 233, 0.22) 44%, transparent 72%)`;
    const emblemSpecularBg = useMotionTemplate`radial-gradient(150px circle at ${emblemGlintX}% ${emblemGlintY}%, rgba(255, 255, 255, 0.72), rgba(243, 231, 233, 0.18) 48%, transparent 76%)`;

    const handlePointerMove = (event: PointerEvent<HTMLDivElement>) => {
        if (shouldReduceMotion || event.pointerType === 'touch') {
            return;
        }
        const el = containerRef.current;
        if (!el) {
            return;
        }
        const rect = el.getBoundingClientRect();
        if (rect.width < 1 || rect.height < 1) {
            return;
        }

        const px = Math.max(
            0,
            Math.min(1, (event.clientX - rect.left) / rect.width),
        );
        const py = Math.max(
            0,
            Math.min(1, (event.clientY - rect.top) / rect.height),
        );

        rawRotateX.set((0.5 - py) * 16);
        rawRotateY.set((px - 0.5) * 18);
        glintX.set(Math.round(px * 100));
        glintY.set(Math.round(py * 100));
        emblemGlintX.set(Math.round(15 + px * 70));
        emblemGlintY.set(Math.round(12 + py * 72));
    };

    const handlePointerLeave = () => {
        rawRotateX.set(6);
        rawRotateY.set(-8);
        glintX.set(48);
        glintY.set(36);
        emblemGlintX.set(44);
        emblemGlintY.set(30);
    };

    return (
        <div
            ref={containerRef}
            onPointerMove={handlePointerMove}
            onPointerLeave={handlePointerLeave}
            className="border-border relative isolate flex min-h-48 items-center justify-center overflow-hidden border-b bg-gradient-to-br from-[#F6F4F3] via-[#ECE8E6] to-[#E2DDDA] px-8 py-8 sm:min-h-60 sm:px-10 sm:py-10 dark:from-[#262429] dark:via-[#1F1D22] dark:to-[#19171B]"
            style={{
                perspective: '1000px',
            }}
        >
            {/* Ambient optical light field */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0"
                style={{
                    backgroundImage:
                        'radial-gradient(circle at 22% 24%, rgba(169,37,59,0.15), transparent 54%), radial-gradient(circle at 80% 76%, rgba(41,39,43,0.09), transparent 52%)',
                }}
            />

            {/* Pointer-tracked chamber reflection */}
            <motion.div
                aria-hidden="true"
                style={{ background: chamberGlintBg }}
                className="pointer-events-none absolute inset-0 z-0 opacity-85"
            />

            {/* 3D Spatial Emblem Assembly */}
            <motion.div
                initial={
                    shouldReduceMotion
                        ? false
                        : { opacity: 0, scale: 0.92, rotateX: 16, rotateY: -18 }
                }
                animate={
                    shouldReduceMotion ? undefined : { opacity: 1, scale: 1 }
                }
                transition={{ duration: 0.65, ease: [0.16, 1, 0.3, 1] }}
                style={
                    shouldReduceMotion
                        ? undefined
                        : {
                              rotateX,
                              rotateY,
                              transformStyle: 'preserve-3d',
                          }
                }
                className="relative z-10 flex h-40 w-full max-w-72 items-center justify-center will-change-transform sm:h-44"
            >
                {/* Plane -42px: Counter-parallax Ambient Floor Shadow */}
                <motion.div
                    aria-hidden="true"
                    style={
                        shouldReduceMotion
                            ? { transform: 'translateZ(-42px)' }
                            : {
                                  x: shadowX,
                                  y: shadowY,
                                  transform: 'translateZ(-42px)',
                              }
                    }
                    className="pointer-events-none absolute inset-x-7 inset-y-5 rounded-[30px] bg-[#29272B]/15 blur-xl dark:bg-black/50"
                />

                {/* Plane -26px: Warm Titanium Machined Backplate & Telemetry Ring */}
                <div
                    aria-hidden="true"
                    style={{ transform: 'translate3d(0, 0, -26px)' }}
                    className="pointer-events-none absolute inset-x-4 inset-y-2 rounded-[26px] border border-black/10 bg-gradient-to-b from-white/62 via-[#E9E5E3]/70 to-[#D9D5D3]/75 shadow-[0_20px_42px_rgba(41,39,43,0.14),inset_0_1px_0_rgba(255,255,255,0.88)] dark:border-white/12 dark:from-white/8 dark:to-black/40"
                >
                    <svg
                        viewBox="0 0 260 150"
                        className="h-full w-full opacity-65"
                        fill="none"
                    >
                        <ellipse
                            cx="130"
                            cy="75"
                            rx="96"
                            ry="48"
                            stroke="currentColor"
                            strokeOpacity="0.14"
                            strokeWidth="1"
                            strokeDasharray="4 4"
                        />
                        <ellipse
                            cx="130"
                            cy="75"
                            rx="68"
                            ry="33"
                            stroke="#A9253B"
                            strokeOpacity="0.24"
                            strokeWidth="1.2"
                        />
                        <circle
                            cx="34"
                            cy="75"
                            r="3"
                            fill="#A9253B"
                            fillOpacity="0.55"
                        />
                        <circle
                            cx="226"
                            cy="75"
                            r="3"
                            fill="#A9253B"
                            fillOpacity="0.55"
                        />
                        <circle
                            cx="130"
                            cy="27"
                            r="2.5"
                            fill="#29272B"
                            fillOpacity="0.35"
                        />
                        <circle
                            cx="130"
                            cy="123"
                            r="2.5"
                            fill="#29272B"
                            fillOpacity="0.35"
                        />
                    </svg>
                </div>

                {/* Plane -8px: Crimson & Champagne-Pink Orbital Waveguide Ring */}
                <motion.div
                    aria-hidden="true"
                    style={
                        shouldReduceMotion
                            ? { transform: 'translateZ(-8px)' }
                            : {
                                  x: ringX,
                                  y: ringY,
                                  transform: 'translateZ(-8px)',
                              }
                    }
                    className="pointer-events-none absolute size-44 rounded-full border border-[#A9253B]/18 bg-gradient-to-tr from-[#A9253B]/[0.06] via-transparent to-white/45 shadow-[inset_0_1px_1px_rgba(255,255,255,0.65)] sm:size-48 dark:border-[#D63D55]/24 dark:from-[#D63D55]/10"
                />

                {/* Plane +8px: Translucent Liquid Glass Optical Disc */}
                <div
                    aria-hidden="true"
                    style={{ transform: 'translate3d(0, 0, 8px)' }}
                    className="pointer-events-none absolute size-36 rounded-full border border-white/80 bg-gradient-to-br from-white/75 via-white/35 to-[#F3E7E9]/45 shadow-[0_12px_28px_rgba(41,39,43,0.10),inset_0_1.5px_1px_rgba(255,255,255,0.95)] backdrop-blur-md sm:size-40 dark:border-white/20 dark:from-white/16 dark:via-white/6 dark:to-[#A9253B]/15"
                />

                {/* Plane +20px: Physical Slab Extrusion Bevel (3D Thickness Layer) */}
                <div
                    aria-hidden="true"
                    style={{ transform: 'translate3d(0, 2px, 20px)' }}
                    className="pointer-events-none absolute h-[74px] w-[196px] rounded-2xl border border-[#29272B]/12 bg-gradient-to-b from-[#EAE6E4] to-[#D4CECB] shadow-[0_16px_32px_rgba(41,39,43,0.16)] sm:h-[82px] sm:w-[228px] dark:border-white/12 dark:from-[#323036] dark:to-[#1C1A1F]"
                />

                {/* Plane +28px: Elevated AD Com BrandMark Emblem */}
                <div
                    style={{ transform: 'translate3d(0, 0, 28px)' }}
                    className="relative isolate flex items-center justify-center overflow-hidden rounded-2xl border border-white/85 bg-white/88 px-6 py-4 shadow-[0_16px_34px_rgba(41,39,43,0.16),0_0_0_1px_rgba(41,39,43,0.06),inset_0_1px_0_rgba(255,255,255,0.98)] backdrop-blur-md dark:border-white/20 dark:bg-[#29272B]/90"
                >
                    <motion.span
                        aria-hidden="true"
                        style={{ background: emblemSpecularBg }}
                        className="pointer-events-none absolute inset-0 -z-10 rounded-[inherit]"
                    />
                    <BrandMark className="w-40 sm:w-48" />
                </div>

                {/* Plane +44px: Top-Right Floating Telemetry Node */}
                <motion.div
                    aria-hidden="true"
                    style={
                        shouldReduceMotion
                            ? { transform: 'translateZ(44px)' }
                            : {
                                  x: badgeX,
                                  y: badgeY,
                                  transform: 'translateZ(44px)',
                              }
                    }
                    className="pointer-events-none absolute -top-1 right-3 flex items-center gap-1.5 rounded-full border border-white/80 bg-white/88 px-2.5 py-1 text-[10px] font-semibold tracking-tight text-[#29272B] shadow-[0_8px_18px_rgba(41,39,43,0.12)] backdrop-blur-md dark:border-white/15 dark:bg-[#29272B]/90 dark:text-[#F2F0EF]"
                >
                    <span className="size-1.5 rounded-full bg-[#A9253B]" />
                    <span>TEFA · TKJ</span>
                </motion.div>

                {/* Plane +36px: Bottom-Left Floating Service Domain Node */}
                <motion.div
                    aria-hidden="true"
                    style={
                        shouldReduceMotion
                            ? { transform: 'translateZ(36px)' }
                            : {
                                  x: ringX,
                                  y: ringY,
                                  transform: 'translateZ(36px)',
                              }
                    }
                    className="pointer-events-none absolute -bottom-1.5 left-2.5 flex items-center rounded-full border border-[#29272B]/10 bg-[#FAF9F8]/90 px-2.5 py-1 text-[10px] font-medium tracking-tight text-[#5F5B61] shadow-[0_8px_18px_rgba(41,39,43,0.10)] backdrop-blur-md dark:border-white/14 dark:bg-[#242227]/90 dark:text-[#D9D5D3]"
                >
                    <span>Jaringan · CCTV · Komputer</span>
                </motion.div>
            </motion.div>
        </div>
    );
}

export default HeroNetworkEmblem3D;

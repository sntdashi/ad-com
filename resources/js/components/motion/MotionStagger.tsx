import { motion, useReducedMotion } from 'motion/react';
import type { ReactNode } from 'react';
import { VARIANTS } from '@/lib/motion';

export function MotionStagger({
    children,
    className,
    inView = false,
}: {
    children: ReactNode;
    className?: string;
    /** Trigger stagger when scrolled into viewport rather than immediately on mount. */
    inView?: boolean;
}) {
    const shouldReduceMotion = useReducedMotion();

    return (
        <motion.div
            variants={VARIANTS.staggerContainer}
            initial={shouldReduceMotion ? false : 'hidden'}
            animate={inView ? undefined : 'visible'}
            whileInView={inView ? 'visible' : undefined}
            viewport={inView ? { once: true, amount: 0.16 } : undefined}
            className={className}
        >
            {children}
        </motion.div>
    );
}

export function MotionStaggerItem({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <motion.div variants={VARIANTS.staggerItem} className={className}>
            {children}
        </motion.div>
    );
}

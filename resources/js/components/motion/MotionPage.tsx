import { motion } from 'motion/react';
import type { ReactNode } from 'react';
import { VARIANTS } from '@/lib/motion';

type MotionPageProps = {
    pageKey: string;
    children: ReactNode;
    className?: string;
};

export function MotionPage({ pageKey, children, className }: MotionPageProps) {
    return (
        <motion.div
            key={pageKey}
            variants={VARIANTS.page}
            initial="initial"
            animate="animate"
            className={className}
        >
            {children}
        </motion.div>
    );
}

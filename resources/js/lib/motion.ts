import type { Transition, Variants } from 'motion/react';

export const MOTION_DURATION = {
    micro: 0.14,
    press: 0.12,
    popover: 0.2,
    modal: 0.26,
    page: 0.22,
    stagger: 0.045,
} as const;

export const EASE = {
    apple: [0.22, 1, 0.36, 1] as const,
    appleOut: [0.16, 1, 0.3, 1] as const,
} as const;

export const SPRING = {
    snappy: {
        type: 'spring',
        stiffness: 400,
        damping: 32,
        mass: 0.85,
    } satisfies Transition,
    fluid: {
        type: 'spring',
        stiffness: 300,
        damping: 28,
        mass: 0.9,
    } satisfies Transition,
    modal: {
        type: 'spring',
        stiffness: 340,
        damping: 30,
        mass: 0.85,
    } satisfies Transition,
    pill: {
        type: 'spring',
        stiffness: 380,
        damping: 34,
        mass: 0.8,
    } satisfies Transition,
    gentle: {
        type: 'spring',
        stiffness: 240,
        damping: 26,
    } satisfies Transition,
    emblem: {
        type: 'spring',
        stiffness: 240,
        damping: 26,
        mass: 0.85,
    } satisfies Transition,
    tilt: {
        type: 'spring',
        stiffness: 260,
        damping: 24,
        mass: 0.75,
    } satisfies Transition,
} as const;

export const VARIANTS = {
    // Starts at 0.94 opacity so Inertia route swaps NEVER produce a blank frame or flash.
    page: {
        initial: { opacity: 0.94, y: 4 },
        animate: {
            opacity: 1,
            y: 0,
            transition: {
                duration: MOTION_DURATION.page,
                ease: EASE.apple,
            },
        },
    } satisfies Variants,

    popover: {
        initial: { opacity: 0, scale: 0.97, y: -3 },
        animate: {
            opacity: 1,
            scale: 1,
            y: 0,
            transition: SPRING.snappy,
        },
        exit: {
            opacity: 0,
            scale: 0.98,
            y: -2,
            transition: { duration: 0.13, ease: EASE.apple },
        },
    } satisfies Variants,

    assistantPanel: {
        initial: { opacity: 0, scale: 0.95, y: 12 },
        animate: {
            opacity: 1,
            scale: 1,
            y: 0,
            transition: SPRING.modal,
        },
        exit: {
            opacity: 0,
            scale: 0.96,
            y: 10,
            transition: { duration: 0.16, ease: EASE.apple },
        },
    } satisfies Variants,

    messageBubble: {
        initial: { opacity: 0, y: 6, scale: 0.99 },
        animate: {
            opacity: 1,
            y: 0,
            scale: 1,
            transition: SPRING.fluid,
        },
    } satisfies Variants,

    staggerContainer: {
        hidden: { opacity: 1 },
        visible: {
            opacity: 1,
            transition: {
                staggerChildren: MOTION_DURATION.stagger,
                delayChildren: 0.02,
            },
        },
    } satisfies Variants,

    staggerItem: {
        hidden: { opacity: 0, y: 10 },
        visible: {
            opacity: 1,
            y: 0,
            transition: SPRING.fluid,
        },
    } satisfies Variants,

    sectionHeaderContainer: {
        hidden: { opacity: 1 },
        visible: {
            opacity: 1,
            transition: {
                staggerChildren: 0.06,
                delayChildren: 0.01,
            },
        },
    } satisfies Variants,

    sectionHeaderItem: {
        hidden: { opacity: 0, y: 12 },
        visible: {
            opacity: 1,
            y: 0,
            transition: SPRING.fluid,
        },
    } satisfies Variants,

    scrollSection: {
        hidden: { opacity: 0, y: 18 },
        visible: {
            opacity: 1,
            y: 0,
            transition: {
                duration: 0.48,
                ease: EASE.appleOut,
            },
        },
    } satisfies Variants,

    wordContainer: {
        hidden: { opacity: 1 },
        visible: {
            opacity: 1,
            transition: {
                staggerChildren: 0.055,
                delayChildren: 0.04,
            },
        },
    } satisfies Variants,

    wordItem: {
        hidden: { opacity: 0, y: 14, rotateX: -18 },
        visible: {
            opacity: 1,
            y: 0,
            rotateX: 0,
            transition: {
                duration: 0.44,
                ease: EASE.appleOut,
            },
        },
    } satisfies Variants,
} as const;

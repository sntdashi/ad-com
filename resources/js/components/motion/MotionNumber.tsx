import { animate, useInView, useReducedMotion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';

type MotionNumberProps = {
    value: number;
    prefix?: string;
    suffix?: string;
    format?: (n: number) => string;
    className?: string;
};

export function MotionNumber({
    value,
    prefix = '',
    suffix = '',
    format,
    className,
}: MotionNumberProps) {
    const ref = useRef<HTMLSpanElement>(null);
    const inView = useInView(ref, { once: true, amount: 0.3 });
    const shouldReduceMotion = useReducedMotion();
    const [display, setDisplay] = useState(value);
    const hasAnimated = useRef(false);

    useEffect(() => {
        if (shouldReduceMotion || !inView || hasAnimated.current) {
            setDisplay(value);
            return;
        }

        hasAnimated.current = true;
        const controls = animate(0, value, {
            duration: 0.72,
            ease: [0.16, 1, 0.3, 1],
            onUpdate: (latest) => {
                setDisplay(Math.round(latest));
            },
            onComplete: () => {
                setDisplay(value);
            },
        });

        return () => controls.stop();
    }, [inView, value, shouldReduceMotion]);

    const formattedFinal = format
        ? format(value)
        : value.toLocaleString('id-ID');
    const formattedDisplay = format
        ? format(display)
        : display.toLocaleString('id-ID');

    return (
        <span
            ref={ref}
            className={className}
            aria-label={`${prefix}${formattedFinal}${suffix}`}
        >
            <span aria-hidden="true">
                {prefix}
                {formattedDisplay}
                {suffix}
            </span>
        </span>
    );
}

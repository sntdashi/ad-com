import { useCallback, useEffect, useRef, type PointerEvent } from 'react';

/**
 * High-performance pointer & touch lighting engine.
 * Updates CSS custom properties (--lg-x, --lg-y, --lg-active) via rAF
 * directly on the target element — zero React re-renders during pointer movement.
 */
export function usePointerLight<T extends HTMLElement = HTMLElement>() {
    const ref = useRef<T>(null);
    const rafRef = useRef<number | null>(null);
    const pendingRef = useRef<{ x: number; y: number } | null>(null);

    const flush = useCallback(() => {
        rafRef.current = null;
        const el = ref.current;
        const coords = pendingRef.current;
        if (!el || !coords) {
            return;
        }
        el.style.setProperty('--lg-x', `${coords.x}%`);
        el.style.setProperty('--lg-y', `${coords.y}%`);
    }, []);

    const schedule = useCallback(
        (clientX: number, clientY: number) => {
            const el = ref.current;
            if (!el) {
                return;
            }
            const rect = el.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) {
                return;
            }
            const x = Math.max(
                0,
                Math.min(100, ((clientX - rect.left) / rect.width) * 100),
            );
            const y = Math.max(
                0,
                Math.min(100, ((clientY - rect.top) / rect.height) * 100),
            );
            pendingRef.current = { x, y };
            if (rafRef.current === null) {
                rafRef.current = requestAnimationFrame(flush);
            }
        },
        [flush],
    );

    useEffect(() => {
        return () => {
            if (rafRef.current !== null) {
                cancelAnimationFrame(rafRef.current);
            }
        };
    }, []);

    const onPointerEnter = useCallback(
        (event: PointerEvent<T>) => {
            ref.current?.style.setProperty('--lg-active', '1');
            schedule(event.clientX, event.clientY);
        },
        [schedule],
    );

    const onPointerMove = useCallback(
        (event: PointerEvent<T>) => {
            schedule(event.clientX, event.clientY);
        },
        [schedule],
    );

    const onPointerLeave = useCallback(() => {
        ref.current?.style.setProperty('--lg-active', '0');
    }, []);

    const onPointerDown = useCallback(
        (event: PointerEvent<T>) => {
            ref.current?.style.setProperty('--lg-active', '1');
            ref.current?.style.setProperty('--lg-press', '1');
            schedule(event.clientX, event.clientY);
        },
        [schedule],
    );

    const onPointerUp = useCallback(() => {
        ref.current?.style.setProperty('--lg-press', '0');
    }, []);

    return {
        ref,
        pointerHandlers: {
            onPointerEnter,
            onPointerMove,
            onPointerLeave,
            onPointerDown,
            onPointerUp,
            onPointerCancel: onPointerUp,
        },
    };
}

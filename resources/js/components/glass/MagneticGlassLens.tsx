import type { ReactNode } from 'react';
import { LiquidGlassLens } from '@/components/glass/LiquidGlassLens';

type MagneticGlassLensProps = {
    children: ReactNode;
    className?: string;
    lensSize?: number;
};

/**
 * Optical lens wrapper for interactive cards/surfaces.
 * Refracts actual card content underneath on pointer/touch hover without
 * placing a decorative white overlay circle on top.
 */
export function MagneticGlassLensSurface({
    children,
    className,
    lensSize = 112,
}: MagneticGlassLensProps) {
    return (
        <LiquidGlassLens
            width={lensSize}
            height={lensSize}
            hiddenOnIdle
            className={className}
            optics={{
                refraction: 14,
                bezel: 0.72,
                curvature: 3.2,
                chroma: 0.14,
                specular: 0.45,
                specularWidth: 1.5,
            }}
        >
            {children}
        </LiquidGlassLens>
    );
}

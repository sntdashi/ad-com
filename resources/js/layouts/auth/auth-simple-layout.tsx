import { Link } from '@inertiajs/react';
import BrandMark from '@/components/brand-mark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-transparent p-5 md:p-10">
            <div className="glass-floating glass-modal-enter relative w-full max-w-md rounded-[28px] p-6 sm:p-8">
                <div className="flex flex-col gap-7">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex h-16 w-24 items-center justify-center p-2">
                                <BrandMark className="w-full" />
                            </div>
                            <span className="sr-only">AD Com, beranda</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <p className="section-kicker text-cyan">
                                AD Com · TEFA TKJ
                            </p>
                            <h1 className="text-foreground text-2xl font-semibold tracking-[-0.025em]">
                                {title}
                            </h1>
                            <p className="text-muted-foreground text-center text-sm">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}

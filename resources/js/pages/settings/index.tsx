import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, BadgeCheck, KeyRound, Sparkles } from 'lucide-react';
import {
    accountSettingsForRole,
    roleSettingsCopy,
    roleToolsForRole,
    type SettingsNavigationItem,
} from '@/lib/settings-navigation';
import { index } from '@/routes/settings';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function SettingsIndex() {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user.role;
    const roleCopy = roleSettingsCopy[role];
    const accountSettings = accountSettingsForRole(role).slice(1);
    const roleTools = roleToolsForRole(role);

    return (
        <>
            <Head title="Pusat pengaturan" />
            <h1 className="sr-only">Pusat pengaturan akun</h1>

            <div className="space-y-6">
                <section className="liquid-panel-dark relative overflow-hidden rounded-[30px] p-6 text-white sm:p-8">
                    <div className="bg-brand/30 pointer-events-none absolute -top-24 -right-20 size-64 rounded-full blur-3xl" />
                    <div className="relative max-w-2xl">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="bg-lime/14 text-lime inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[10px] font-black tracking-[.12em] uppercase">
                                <BadgeCheck className="size-3.5" />
                                {roleCopy.label}
                            </span>
                            <span className="text-[10px] font-black tracking-[.14em] text-white/44 uppercase">
                                {roleCopy.eyebrow}
                            </span>
                        </div>
                        <h2 className="mt-5 text-3xl font-black tracking-[-.045em] sm:text-4xl">
                            Pengaturan yang relevan,
                            <span className="text-lime block">
                                tanpa menu yang bukan hakmu.
                            </span>
                        </h2>
                        <p className="mt-4 max-w-xl text-sm leading-6 text-white/58">
                            {roleCopy.description}
                        </p>
                    </div>
                </section>

                <SettingsSection
                    eyebrow="Akun pribadi"
                    title="Yang bisa kamu atur sendiri"
                    description="Perubahan di bagian ini hanya berlaku pada akunmu."
                    items={accountSettings}
                />

                <SettingsSection
                    eyebrow={`Kontrol ${roleCopy.label}`}
                    title="Akses sesuai tanggung jawab role"
                    description="Daftar ini mengikuti izin backend; menyembunyikan menu tidak menggantikan pemeriksaan hak akses."
                    items={roleTools}
                />

                <section className="liquid-panel flex flex-col gap-4 rounded-[26px] p-5 sm:flex-row sm:items-center sm:p-6">
                    <span className="bg-cyan/10 text-cyan grid size-12 shrink-0 place-items-center rounded-2xl">
                        <KeyRound className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-foreground font-black">
                            Lupa password tetap bisa dipulihkan
                        </h2>
                        <p className="text-muted-foreground mt-1 text-xs leading-5">
                            Dari halaman login, pilih “Lupa kata sandi?”,
                            masukkan email akun, lalu gunakan tautan reset yang
                            dikirim.
                        </p>
                    </div>
                    <Sparkles className="text-cyan hidden size-5 sm:block" />
                </section>
            </div>
        </>
    );
}

function SettingsSection({
    eyebrow,
    title,
    description,
    items,
}: {
    eyebrow: string;
    title: string;
    description: string;
    items: SettingsNavigationItem[];
}) {
    return (
        <section>
            <p className="section-kicker text-cyan">{eyebrow}</p>
            <h2 className="text-foreground mt-1 text-xl font-black">{title}</h2>
            <p className="text-muted-foreground mt-1 text-xs leading-5">
                {description}
            </p>

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                {items.map((item) => {
                    const Icon = item.icon;

                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            prefetch
                            className="liquid-control group hover:border-cyan/25 flex min-h-32 items-start gap-4 rounded-[22px] border p-5 transition hover:-translate-y-0.5"
                        >
                            <span className="bg-cyan/10 text-cyan group-hover:bg-cyan grid size-11 shrink-0 place-items-center rounded-2xl transition-colors group-hover:text-white">
                                <Icon className="size-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="text-foreground flex items-center justify-between gap-2 text-sm font-black">
                                    {item.title}
                                    <ArrowUpRight className="text-muted-foreground/60 size-4 shrink-0 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                                </span>
                                <span className="text-muted-foreground mt-2 block text-xs leading-5">
                                    {item.description}
                                </span>
                            </span>
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}

SettingsIndex.layout = {
    breadcrumbs: [{ title: 'Pusat pengaturan', href: index() }],
};

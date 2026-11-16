import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    BadgeCheck,
    BriefcaseBusiness,
    Plus,
    Search,
    Shield,
    ShieldCheck,
    UserCog,
    Warehouse,
    Wrench,
    Star,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    index as teamIndex,
    store as storeMember,
    update as updateMember,
} from '@/routes/team';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
    phone: string | null;
    avatar_url: string | null;
    specialty: string | null;
    bio: string | null;
    show_phone_publicly: boolean;
    is_active: boolean;
    active_assignments_count: number;
    reviews_count: number;
    rating_average: number | null;
    is_self: boolean;
    can_edit: boolean;
};

const roleLabels: Record<string, string> = {
    operator: 'Superadmin',
    admin: 'Admin',
    supervisor: 'Supervisor',
    technician: 'Teknisi',
    warehouse: 'Gudang',
    customer: 'Customer',
};

const initials = (name: string) =>
    name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

type RolePermission = {
    role: string;
    label: string;
    scope: string;
    capabilities: string[];
};

export default function TeamPage({
    role,
    filters,
    roleCounts,
    rolePermissions,
    members,
    openOrders,
}: {
    role: string;
    filters: { role: string; filter: string; search: string };
    roleCounts: Record<string, number>;
    rolePermissions: RolePermission[];
    members: Member[];
    openOrders: number;
}) {
    const [search, setSearch] = useState(filters.search ?? '');

    const summaries = [
        ['operator', 'Superadmin', roleCounts.operator ?? 0, ShieldCheck],
        ['admin', 'Admin', roleCounts.admin ?? 0, UserCog],
        ['supervisor', 'Supervisor', roleCounts.supervisor ?? 0, Shield],
        ['technician', 'Teknisi', roleCounts.technician ?? 0, Wrench],
        ['warehouse', 'Gudang', roleCounts.warehouse ?? 0, Warehouse],
    ] as const;

    const filterLabels: Record<string, string> = {
        active: 'Akun aktif',
        inactive: 'Akun nonaktif',
        missing_phone: 'Tanpa nomor WhatsApp',
    };

    const hasActiveFilters = Boolean(
        filters.role || filters.filter || filters.search,
    );

    function applyFilters(next: {
        role?: string;
        filter?: string;
        search?: string;
    }) {
        const query = {
            role: (next.role ?? filters.role) || undefined,
            filter: (next.filter ?? filters.filter) || undefined,
            search: (next.search ?? search).trim() || undefined,
        };

        router.get(teamIndex.url({ query }), {}, { preserveScroll: true });
    }

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        applyFilters({ search });
    }

    return (
        <>
            <Head title="Tim & Hak Akses" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl">
                    <header className="liquid-panel-dark rounded-[28px] p-6 text-white sm:p-8">
                        <p className="text-cyan text-xs font-black tracking-[.15em] uppercase">
                            Identity & access control
                        </p>
                        <div className="mt-3 flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
                            <div>
                                <h1 className="text-3xl font-black tracking-[-.045em] sm:text-4xl">
                                    Orang yang tepat. Akses yang tepat.
                                </h1>
                                <p className="mt-3 max-w-2xl text-sm leading-6 text-white/58">
                                    Operator mengendalikan role tertinggi. Admin
                                    mengelola tim harian tanpa dapat mengambil
                                    alih akun operator.
                                </p>
                            </div>
                            <div className="border-lime/25 bg-lime/10 rounded-2xl border px-5 py-3">
                                <p className="text-lime text-[10px] font-black uppercase">
                                    Tiket aktif
                                </p>
                                <p className="mt-1 text-2xl font-black">
                                    {openOrders}
                                </p>
                            </div>
                        </div>
                    </header>

                    <section className="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
                        {summaries.map(([key, label, value, Icon]) => {
                            const selected = filters.role === key;

                            return (
                                <Link
                                    key={key}
                                    href={teamIndex({
                                        query: {
                                            role: selected ? undefined : key,
                                            filter: filters.filter || undefined,
                                            search: filters.search || undefined,
                                        },
                                    })}
                                    preserveScroll
                                    className={`liquid-panel block rounded-[22px] p-4 transition-colors ${
                                        selected
                                            ? 'border-cyan ring-cyan/25 ring-2'
                                            : 'hover:border-cyan/40'
                                    }`}
                                >
                                    <Icon className="text-cyan size-5" />
                                    <p className="text-ink mt-4 text-2xl font-black">
                                        {value}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs font-bold">
                                        {label}
                                    </p>
                                </Link>
                            );
                        })}
                    </section>

                    <section className="liquid-panel mt-5 rounded-[24px] p-4 sm:p-5">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <form
                                onSubmit={submitSearch}
                                className="flex flex-1 flex-wrap items-center gap-2"
                            >
                                <div className="relative min-w-52 flex-1">
                                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2" />
                                    <Input
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        placeholder="Cari nama, email, nomor WhatsApp, atau spesialisasi..."
                                        className="pl-10"
                                    />
                                </div>
                                <select
                                    aria-label="Filter role"
                                    className="liquid-input h-10 rounded-xl px-3 text-xs font-bold"
                                    value={filters.role}
                                    onChange={(event) =>
                                        applyFilters({
                                            role: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">Semua role</option>
                                    <option value="operator">Superadmin</option>
                                    <option value="admin">Admin</option>
                                    <option value="supervisor">
                                        Supervisor
                                    </option>
                                    <option value="technician">Teknisi</option>
                                    <option value="warehouse">Gudang</option>
                                    <option value="customer">Customer</option>
                                </select>
                                <select
                                    aria-label="Filter status akun"
                                    className="liquid-input h-10 rounded-xl px-3 text-xs font-bold"
                                    value={filters.filter}
                                    onChange={(event) =>
                                        applyFilters({
                                            filter: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">Semua status</option>
                                    <option value="active">Akun aktif</option>
                                    <option value="inactive">
                                        Akun nonaktif
                                    </option>
                                    <option value="missing_phone">
                                        Tanpa WhatsApp
                                    </option>
                                </select>
                                <Button
                                    type="submit"
                                    className="h-10 rounded-xl px-4 text-xs font-black"
                                >
                                    Terapkan
                                </Button>
                                {hasActiveFilters && (
                                    <Link
                                        href={teamIndex()}
                                        className="text-muted-foreground hover:text-foreground rounded-xl px-3 py-2 text-xs font-bold"
                                    >
                                        Reset
                                    </Link>
                                )}
                            </form>
                            <p className="text-muted-foreground text-xs font-bold">
                                Menampilkan {members.length} akun
                                {filters.role
                                    ? ` · ${roleLabels[filters.role] ?? filters.role}`
                                    : ''}
                                {filters.filter
                                    ? ` · ${filterLabels[filters.filter] ?? filters.filter}`
                                    : ''}
                            </p>
                        </div>

                        <details className="border-border mt-4 border-t pt-3">
                            <summary className="text-muted-foreground hover:text-foreground flex cursor-pointer list-none items-center gap-2 text-xs font-black">
                                <Plus className="text-cyan size-4" /> Tambah
                                anggota tim baru +
                            </summary>
                            <CreateMemberForm actorRole={role} />
                        </details>

                        <details className="border-border mt-3 border-t pt-3">
                            <summary className="text-muted-foreground hover:text-foreground cursor-pointer list-none text-xs font-black">
                                Matriks hak akses role ({rolePermissions.length}{' '}
                                role) +
                            </summary>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {rolePermissions.map((item) => (
                                    <div
                                        key={item.role}
                                        className="surface-tile rounded-2xl p-3.5"
                                    >
                                        <p className="text-cyan text-xs font-black">
                                            {item.label}
                                        </p>
                                        <p className="text-foreground mt-1 text-xs font-bold">
                                            {item.scope}
                                        </p>
                                        <ul className="text-muted-foreground mt-2 space-y-1 text-[11px]">
                                            {item.capabilities.map((cap) => (
                                                <li key={cap}>• {cap}</li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        </details>
                    </section>

                    <section className="mt-5 grid gap-4 lg:grid-cols-2">
                        {members.length > 0 ? (
                            members.map((member) => (
                                <MemberCard
                                    key={member.id}
                                    member={member}
                                    actorRole={role}
                                />
                            ))
                        ) : (
                            <div className="liquid-panel rounded-[26px] p-8 text-center lg:col-span-2">
                                <p className="text-foreground font-black">
                                    Tidak ada akun yang cocok dengan filter ini.
                                </p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Ubah filter role/status atau reset pencarian
                                    untuk melihat seluruh tim.
                                </p>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

function CreateMemberForm({ actorRole }: { actorRole: string }) {
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        role: 'technician',
        specialty: '',
        password: '',
        is_active: true,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(storeMember.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <form
            onSubmit={submit}
            className="surface-tile mt-3 grid gap-3 rounded-2xl p-4 sm:grid-cols-2"
        >
            <Field label="Nama lengkap" error={form.errors.name}>
                <Input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                />
            </Field>
            <Field label="Email" error={form.errors.email}>
                <Input
                    type="email"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    required
                />
            </Field>
            <Field label="Role" error={form.errors.role}>
                <select
                    className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                    value={form.data.role}
                    onChange={(e) => form.setData('role', e.target.value)}
                >
                    {actorRole === 'operator' && (
                        <option value="operator">Superadmin</option>
                    )}
                    <option value="admin">Admin</option>
                    <option value="supervisor">Supervisor</option>
                    <option value="technician">Teknisi</option>
                    <option value="warehouse">Gudang</option>
                    <option value="customer">Customer</option>
                </select>
            </Field>
            <Field label="Nomor WhatsApp" error={form.errors.phone}>
                <Input
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                    placeholder="628123456789"
                />
            </Field>
            <Field label="Spesialisasi" error={form.errors.specialty}>
                <Input
                    value={form.data.specialty}
                    onChange={(e) => form.setData('specialty', e.target.value)}
                    placeholder="Opsional (mis. Jaringan & CCTV)"
                />
            </Field>
            <Field label="Password awal" error={form.errors.password}>
                <Input
                    type="password"
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    required
                />
            </Field>
            <div className="sm:col-span-2">
                <Button
                    disabled={form.processing}
                    className="h-10 rounded-full px-5"
                >
                    <Plus /> Tambah anggota
                </Button>
            </div>
        </form>
    );
}

function MemberCard({
    member,
    actorRole,
}: {
    member: Member;
    actorRole: string;
}) {
    const form = useForm({
        role: member.role,
        phone: member.phone ?? '',
        avatar_url: member.avatar_url ?? '',
        specialty: member.specialty ?? '',
        bio: member.bio ?? '',
        show_phone_publicly: member.show_phone_publicly,
        is_active: member.is_active,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(updateMember.url(member.id), { preserveScroll: true });
    }

    return (
        <article className="liquid-panel overflow-hidden rounded-[26px]">
            <div className="flex items-start gap-4 p-5 sm:p-6">
                <Avatar className="size-14 border-2 border-white shadow-lg">
                    <AvatarImage
                        src={member.avatar_url ?? undefined}
                        alt={member.name}
                    />
                    <AvatarFallback className="bg-cyan/10 text-cyan font-black">
                        {initials(member.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-ink truncate font-black">
                            {member.name}
                        </h2>
                        {member.is_self && (
                            <span className="bg-cyan/10 text-cyan rounded-full px-2 py-0.5 text-[10px] font-black">
                                ANDA
                            </span>
                        )}
                        <span
                            className={`rounded-full px-2 py-0.5 text-[10px] font-black ${member.is_active ? 'status-pill-success' : 'status-pill-danger'}`}
                        >
                            {member.is_active ? 'AKTIF' : 'NONAKTIF'}
                        </span>
                    </div>
                    <p className="text-muted-foreground mt-1 truncate text-xs">
                        {member.email}
                    </p>
                    <p className="text-cyan mt-2 text-xs font-bold">
                        {roleLabels[member.role] ?? member.role}
                        {member.specialty ? ` · ${member.specialty}` : ''}
                    </p>
                    {member.role === 'technician' && (
                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-2 text-[11px]">
                            <span>
                                {member.active_assignments_count} tiket aktif
                            </span>
                            <span className="flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                <Star className="size-3 fill-current" />
                                <strong>
                                    {member.rating_average?.toFixed(1) ??
                                        'Baru'}
                                </strong>
                                <span className="text-muted-foreground/75">
                                    ({member.reviews_count})
                                </span>
                            </span>
                        </div>
                    )}
                </div>
            </div>

            {member.can_edit ? (
                <details className="border-border border-t">
                    <summary className="text-muted-foreground hover:text-foreground flex cursor-pointer list-none items-center justify-between px-5 py-4 text-xs font-black sm:px-6">
                        <span className="flex items-center gap-2">
                            <UserCog className="size-4" /> Edit profil & akses
                        </span>
                        <span>+</span>
                    </summary>
                    <form
                        onSubmit={submit}
                        className="border-border bg-muted/35 grid gap-4 border-t p-5 sm:grid-cols-2 sm:p-6"
                    >
                        <Field label="Role" error={form.errors.role}>
                            <select
                                className="liquid-input h-11 w-full rounded-xl px-3 text-sm"
                                value={form.data.role}
                                onChange={(event) =>
                                    form.setData('role', event.target.value)
                                }
                            >
                                {actorRole === 'operator' && (
                                    <option value="operator">Superadmin</option>
                                )}
                                <option value="admin">Admin</option>
                                <option value="supervisor">Supervisor</option>
                                <option value="technician">Teknisi</option>
                                <option value="warehouse">Gudang</option>
                                <option value="customer">Customer</option>
                            </select>
                        </Field>
                        <Field label="Nomor HP" error={form.errors.phone}>
                            <Input
                                value={form.data.phone}
                                onChange={(event) =>
                                    form.setData('phone', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Spesialisasi"
                            error={form.errors.specialty}
                            wide
                        >
                            <Input
                                value={form.data.specialty}
                                onChange={(event) =>
                                    form.setData(
                                        'specialty',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="URL foto profil"
                            error={form.errors.avatar_url}
                            wide
                        >
                            <Input
                                type="url"
                                value={form.data.avatar_url}
                                onChange={(event) =>
                                    form.setData(
                                        'avatar_url',
                                        event.target.value,
                                    )
                                }
                                placeholder="https://..."
                            />
                        </Field>
                        <Field label="Bio singkat" error={form.errors.bio} wide>
                            <textarea
                                className="liquid-input min-h-24 w-full resize-y rounded-xl px-3 py-2 text-sm"
                                value={form.data.bio}
                                onChange={(event) =>
                                    form.setData('bio', event.target.value)
                                }
                            />
                        </Field>
                        <label className="liquid-control flex items-center gap-3 rounded-xl p-3 text-xs font-bold">
                            <input
                                type="checkbox"
                                checked={form.data.show_phone_publicly}
                                onChange={(event) =>
                                    form.setData(
                                        'show_phone_publicly',
                                        event.target.checked,
                                    )
                                }
                                className="accent-cyan size-4"
                            />{' '}
                            Nomor HP boleh dilihat pelanggan
                        </label>
                        <label className="liquid-control flex items-center gap-3 rounded-xl p-3 text-xs font-bold">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(event) =>
                                    form.setData(
                                        'is_active',
                                        event.target.checked,
                                    )
                                }
                                className="accent-cyan size-4"
                            />{' '}
                            Akun aktif
                        </label>
                        <div className="sm:col-span-2">
                            <Button
                                disabled={form.processing}
                                className="h-11 rounded-full px-6"
                            >
                                <BadgeCheck /> Simpan perubahan
                            </Button>
                        </div>
                    </form>
                </details>
            ) : (
                <div className="border-border text-muted-foreground flex items-center gap-2 border-t px-5 py-4 text-xs">
                    <BriefcaseBusiness className="size-4" />{' '}
                    {member.is_self
                        ? 'Ubah profil sendiri melalui Pengaturan.'
                        : 'Akun operator hanya dapat diubah operator lain.'}
                </div>
            )}
        </article>
    );
}

function Field({
    label,
    error,
    wide = false,
    children,
}: {
    label: string;
    error?: string;
    wide?: boolean;
    children: React.ReactNode;
}) {
    return (
        <label className={wide ? 'sm:col-span-2' : ''}>
            <span className="text-muted-foreground mb-1.5 block text-xs font-bold">
                {label}
            </span>
            {children}
            <InputError message={error} className="mt-1.5" />
        </label>
    );
}

TeamPage.layout = {
    breadcrumbs: [{ title: 'Tim & akses', href: teamIndex() }],
};

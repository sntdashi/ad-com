import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    AtSign,
    BadgeCheck,
    Camera,
    MapPin,
    Phone,
    Sparkles,
    Trash2,
    UserRound,
    Wrench,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import InputError from '@/components/input-error';
import OtpVerificationPanel from '@/components/otp-verification-panel';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

const roleLabels: Record<string, string> = {
    operator: 'Superadmin',
    admin: 'Admin operasional',
    supervisor: 'Supervisor',
    technician: 'Teknisi',
    warehouse: 'Tim gudang',
    customer: 'Customer',
};

const initials = (name: string) =>
    name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

export default function Profile({
    mustVerifyEmail,
    profileCompletion,
    phoneChangeOtpEnabled = false,
    status,
}: {
    mustVerifyEmail: boolean;
    profileCompletion: number;
    phoneChangeOtpEnabled?: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const avatarInput = useRef<HTMLInputElement>(null);
    const [avatarPreview, setAvatarPreview] = useState<string | null>(null);
    const [removeAvatar, setRemoveAvatar] = useState(false);
    const [phoneValue, setPhoneValue] = useState(auth.user.phone ?? '');
    const [otpChallengeId, setOtpChallengeId] = useState('');
    const isTechnician = auth.user.role === 'technician';

    const normalizePhoneDigits = (val: string) => {
        const digits = val.replace(/\D+/g, '');
        return digits.startsWith('0') ? `62${digits.slice(1)}` : digits;
    };
    const isPhoneChanged =
        normalizePhoneDigits(phoneValue) !== '' &&
        normalizePhoneDigits(phoneValue) !==
            normalizePhoneDigits(auth.user.phone ?? '');

    useEffect(
        () => () => {
            if (avatarPreview) {
                URL.revokeObjectURL(avatarPreview);
            }
        },
        [avatarPreview],
    );

    function previewAvatar(file?: File) {
        if (avatarPreview) {
            URL.revokeObjectURL(avatarPreview);
        }

        setAvatarPreview(file ? URL.createObjectURL(file) : null);
        setRemoveAvatar(false);
    }

    function clearAvatar() {
        if (avatarPreview) {
            URL.revokeObjectURL(avatarPreview);
        }

        if (avatarInput.current) {
            avatarInput.current.value = '';
        }

        setAvatarPreview(null);
        setRemoveAvatar(true);
    }

    return (
        <>
            <Head title="Profil saya" />
            <h1 className="sr-only">Profil saya</h1>

            <div className="space-y-6">
                <section className="liquid-panel-dark overflow-hidden rounded-[28px] p-5 text-white sm:p-7">
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
                        <Avatar className="size-24 shrink-0 border-4 border-white/18 shadow-2xl">
                            <AvatarImage
                                src={
                                    removeAvatar
                                        ? undefined
                                        : (avatarPreview ??
                                          auth.user.avatar ??
                                          undefined)
                                }
                                alt={auth.user.name}
                            />
                            <AvatarFallback className="bg-cyan text-xl font-black text-white">
                                {initials(auth.user.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-cyan text-[10px] font-black tracking-[.14em] uppercase">
                                    {roleLabels[auth.user.role] ??
                                        auth.user.role}
                                </p>
                                {auth.user.email_verified_at && (
                                    <span className="bg-lime/15 text-lime inline-flex items-center gap-1 rounded-full px-2 py-1 text-[9px] font-black">
                                        <BadgeCheck className="size-3" /> EMAIL
                                        TERVERIFIKASI
                                    </span>
                                )}
                            </div>
                            <h2 className="mt-2 truncate text-2xl font-black tracking-[-.035em]">
                                {auth.user.name}
                            </h2>
                            <p className="mt-1 text-sm text-white/48">
                                {auth.user.username
                                    ? `@${auth.user.username}`
                                    : 'Tambahkan username unikmu'}
                            </p>
                            <div className="mt-4 max-w-md">
                                <div className="flex items-center justify-between text-[10px] font-black tracking-wide uppercase">
                                    <span className="text-white/45">
                                        Kelengkapan profil
                                    </span>
                                    <span className="text-lime">
                                        {profileCompletion}%
                                    </span>
                                </div>
                                <div className="mt-2 h-2 overflow-hidden rounded-full bg-white/10">
                                    <div
                                        className="from-cyan to-lime h-full rounded-full bg-linear-to-r transition-all"
                                        style={{
                                            width: `${profileCompletion}%`,
                                        }}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                    className="liquid-panel rounded-[28px] p-5 sm:p-7"
                >
                    {({ processing, errors, recentlySuccessful }) => (
                        <>
                            <input
                                type="hidden"
                                name="remove_avatar"
                                value={removeAvatar ? '1' : '0'}
                            />
                            {!isTechnician && (
                                <input
                                    type="hidden"
                                    name="show_phone_publicly"
                                    value="0"
                                />
                            )}

                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        Identitas akun
                                    </p>
                                    <h2 className="text-foreground mt-1 text-xl font-black">
                                        Profil yang bisa kamu kontrol
                                    </h2>
                                    <p className="text-muted-foreground mt-2 text-xs leading-5">
                                        Alamat tetap privat. Nomor teknisi hanya
                                        tampil ke pelanggan jika diizinkan.
                                    </p>
                                </div>
                                <Sparkles className="text-cyan size-6 shrink-0" />
                            </div>

                            <div className="surface-tile mt-6 flex flex-col gap-4 rounded-2xl p-4 sm:flex-row sm:items-center">
                                <div className="bg-cyan/10 text-cyan grid size-11 shrink-0 place-items-center rounded-xl">
                                    <Camera className="size-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <Label
                                        htmlFor="avatar"
                                        className="font-black"
                                    >
                                        Foto profil
                                    </Label>
                                    <p className="text-muted-foreground mt-1 text-[11px]">
                                        JPG, PNG, atau WebP · maks. 2 MB ·
                                        minimal 128×128 px
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="rounded-full"
                                        onClick={() =>
                                            avatarInput.current?.click()
                                        }
                                    >
                                        <Camera /> Pilih foto
                                    </Button>
                                    {(auth.user.avatar || avatarPreview) &&
                                        !removeAvatar && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                className="text-destructive rounded-full"
                                                onClick={clearAvatar}
                                            >
                                                <Trash2 /> Hapus
                                            </Button>
                                        )}
                                </div>
                                <input
                                    ref={avatarInput}
                                    id="avatar"
                                    name="avatar"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    className="sr-only"
                                    onChange={(event) =>
                                        previewAvatar(event.target.files?.[0])
                                    }
                                />
                            </div>
                            <InputError
                                message={errors.avatar}
                                className="mt-2"
                            />

                            <div className="mt-6 grid gap-5 sm:grid-cols-2">
                                <ProfileField
                                    label="Nama lengkap"
                                    icon={UserRound}
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={auth.user.name}
                                        required
                                        autoComplete="name"
                                        placeholder="Nama lengkap"
                                    />
                                </ProfileField>

                                <ProfileField
                                    label="Username"
                                    icon={AtSign}
                                    error={errors.username}
                                >
                                    <Input
                                        id="username"
                                        name="username"
                                        defaultValue={auth.user.username ?? ''}
                                        autoComplete="username"
                                        placeholder="contoh: fikri.adcom"
                                    />
                                </ProfileField>

                                <ProfileField
                                    label="Email"
                                    icon={AtSign}
                                    error={errors.email}
                                >
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={auth.user.email}
                                        required
                                        autoComplete="email"
                                        placeholder="nama@email.com"
                                    />
                                </ProfileField>

                                <ProfileField
                                    label="Nomor HP / WhatsApp"
                                    icon={Phone}
                                    error={errors.phone}
                                >
                                    <Input
                                        id="phone"
                                        name="phone"
                                        inputMode="tel"
                                        value={phoneValue}
                                        onChange={(e) => {
                                            setPhoneValue(e.target.value);
                                            setOtpChallengeId('');
                                        }}
                                        autoComplete="tel"
                                        placeholder="081234567890"
                                    />
                                </ProfileField>

                                {phoneChangeOtpEnabled && isPhoneChanged && (
                                    <div className="sm:col-span-2">
                                        <input
                                            type="hidden"
                                            name="otp_challenge_id"
                                            value={otpChallengeId}
                                        />
                                        <OtpVerificationPanel
                                            purpose="phone_change"
                                            phone={phoneValue}
                                            onVerified={(id) =>
                                                setOtpChallengeId(id)
                                            }
                                        />
                                    </div>
                                )}

                                {isTechnician && (
                                    <ProfileField
                                        label="Spesialisasi teknisi"
                                        icon={Wrench}
                                        error={errors.specialty}
                                        wide
                                    >
                                        <Input
                                            id="specialty"
                                            name="specialty"
                                            defaultValue={
                                                auth.user.specialty ?? ''
                                            }
                                            placeholder="Contoh: jaringan, CCTV, servis komputer"
                                        />
                                    </ProfileField>
                                )}

                                <ProfileField
                                    label="Bio singkat"
                                    icon={Sparkles}
                                    error={errors.bio}
                                    wide
                                >
                                    <textarea
                                        id="bio"
                                        name="bio"
                                        defaultValue={auth.user.bio ?? ''}
                                        maxLength={500}
                                        className="liquid-input focus:ring-cyan/20 min-h-28 w-full resize-y rounded-xl border p-3 text-sm leading-6 outline-none focus:ring-3"
                                        placeholder="Ceritakan sedikit tentang dirimu atau keahlianmu."
                                    />
                                </ProfileField>

                                <ProfileField
                                    label="Alamat"
                                    icon={MapPin}
                                    error={errors.address}
                                    wide
                                >
                                    <textarea
                                        id="address"
                                        name="address"
                                        defaultValue={auth.user.address ?? ''}
                                        maxLength={1000}
                                        autoComplete="street-address"
                                        className="liquid-input focus:ring-cyan/20 min-h-24 w-full resize-y rounded-xl border p-3 text-sm leading-6 outline-none focus:ring-3"
                                        placeholder="Alamat lengkap untuk mempermudah layanan onsite."
                                    />
                                </ProfileField>
                            </div>

                            {isTechnician && (
                                <label className="liquid-control mt-5 flex items-start gap-3 rounded-2xl border p-4">
                                    <input
                                        type="hidden"
                                        name="show_phone_publicly"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="show_phone_publicly"
                                        value="1"
                                        defaultChecked={
                                            auth.user.show_phone_publicly
                                        }
                                        className="accent-cyan mt-0.5 size-4 shrink-0"
                                    />
                                    <span>
                                        <span className="text-foreground block text-sm font-black">
                                            Izinkan nomor HP tampil pada tiket
                                            pelanggan
                                        </span>
                                        <span className="text-muted-foreground mt-1 block text-xs leading-5">
                                            Hanya pelanggan pada tiket yang kamu
                                            tangani yang dapat melihatnya.
                                        </span>
                                    </span>
                                </label>
                            )}

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div className="status-pill-warning mt-5 rounded-2xl p-4 text-xs leading-5">
                                        Email ini belum diverifikasi.{' '}
                                        <Link
                                            href={send()}
                                            as="button"
                                            className="font-black underline underline-offset-4"
                                        >
                                            Kirim ulang tautan verifikasi
                                        </Link>
                                        {status ===
                                            'verification-link-sent' && (
                                            <p className="mt-2 font-black">
                                                Tautan baru sudah dikirim.
                                            </p>
                                        )}
                                    </div>
                                )}

                            <div className="border-border mt-6 flex flex-wrap items-center gap-3 border-t pt-5">
                                <Button
                                    disabled={processing}
                                    className="h-11 rounded-full px-6"
                                    data-test="update-profile-button"
                                >
                                    <BadgeCheck />{' '}
                                    {processing
                                        ? 'Menyimpan…'
                                        : 'Simpan profil'}
                                </Button>
                                {recentlySuccessful && (
                                    <p className="text-xs font-black text-emerald-700">
                                        Profil berhasil diperbarui.
                                    </p>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            </div>

            {auth.user.role === 'customer' && <DeleteUser />}
        </>
    );
}

function ProfileField({
    label,
    icon: Icon,
    error,
    wide = false,
    children,
}: {
    label: string;
    icon: typeof UserRound;
    error?: string;
    wide?: boolean;
    children: React.ReactNode;
}) {
    return (
        <label className={wide ? 'sm:col-span-2' : ''}>
            <span className="text-muted-foreground mb-2 flex items-center gap-2 text-xs font-black">
                <Icon className="text-cyan size-4" /> {label}
            </span>
            {children}
            <InputError message={error} className="mt-1.5" />
        </label>
    );
}

Profile.layout = {
    breadcrumbs: [{ title: 'Profil saya', href: edit() }],
};

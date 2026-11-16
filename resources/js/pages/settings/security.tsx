import { Form, Head } from '@inertiajs/react';
import { KeyRound, ShieldAlert } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { edit } from '@/routes/security';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';

// oxfmt-ignore
type Props = {
    passwordRules: string;
    twoFactorRequired: boolean;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Keamanan & OTP" />

            <h1 className="sr-only">Keamanan dan OTP</h1>

            <div className="space-y-6">
                {props.twoFactorRequired && !props.twoFactorEnabled && (
                    <section className="liquid-panel border-primary/30 bg-primary/8 text-foreground rounded-[22px] border p-5">
                        <div className="flex items-start gap-3">
                            <span className="bg-primary text-primary-foreground grid size-10 shrink-0 place-items-center rounded-xl shadow-xs">
                                <ShieldAlert className="size-5" />
                            </span>
                            <div>
                                <p className="text-xs font-black tracking-[.12em] text-[var(--brand-text)] uppercase">
                                    Wajib untuk tim AD Com
                                </p>
                                <h2 className="text-foreground mt-1 font-bold">
                                    Aktifkan kode OTP sebelum membuka workspace.
                                </h2>
                                <p className="text-muted-foreground mt-2 text-xs leading-5">
                                    Gunakan Google Authenticator atau Microsoft
                                    Authenticator, lalu simpan recovery code di
                                    tempat aman.
                                </p>
                                <a
                                    href="#aktivasi-otp"
                                    className="bg-primary text-primary-foreground mt-3 inline-flex rounded-full px-4 py-2 text-xs font-semibold transition hover:brightness-95"
                                >
                                    Aktifkan OTP sekarang ↓
                                </a>
                            </div>
                        </div>
                    </section>
                )}

                <Heading
                    variant="small"
                    title="Perbarui password"
                    description="Verifikasi kode WhatsApp sebelum menyimpan password baru."
                />

                <Form
                    {...SecurityController.sendPasswordOtp.form()}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-muted-foreground text-sm">
                                Kode akan dikirim ke nomor WhatsApp di profil
                                dan berlaku 5 menit.
                            </p>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                <KeyRound /> Kirim kode WhatsApp
                            </Button>
                            <InputError message={errors.otp} />
                        </>
                    )}
                </Form>

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="otp">Kode OTP WhatsApp</Label>
                                <Input
                                    id="otp"
                                    name="otp"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    placeholder="6 digit"
                                />
                                <InputError message={errors.otp} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Password saat ini
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder="Password saat ini"
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password baru</Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Password baru"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Konfirmasi password
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Ulangi password baru"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Simpan
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <div id="aktivasi-otp" className="scroll-mt-6" />
            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
                twoFactorRequired={props.twoFactorRequired}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Keamanan & OTP',
            href: edit(),
        },
    ],
};

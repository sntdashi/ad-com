import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { redirect as googleRedirect } from '@/actions/App/Http/Controllers/Auth/GoogleAuthController';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import PasskeyVerify from '@/components/passkey-verify';

type Props = {
    status?: string;
    canResetPassword: boolean;
    googleError?: string;
};

export default function Login({
    status,
    canResetPassword,
    googleError,
}: Props) {
    return (
        <>
            <Head title="Masuk ke AD Com" />

            <PasskeyVerify
                label="Masuk dengan passkey"
                loadingLabel="Memverifikasi…"
                separator="Atau lanjut dengan Google"
            />

            <Button asChild variant="outline" className="w-full">
                <a href={googleRedirect.url()} data-test="google-login-button">
                    <GoogleIcon />
                    Lanjutkan dengan Google
                </a>
            </Button>

            <div className="my-5 flex items-center gap-3">
                <Separator className="flex-1" />
                <span className="text-muted-foreground text-[11px] font-medium tracking-wider uppercase">
                    Atau gunakan email
                </span>
                <Separator className="flex-1" />
            </div>

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError
                            message={googleError}
                            className="text-center"
                        />

                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Alamat email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Kata sandi</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            Lupa kata sandi?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Kata sandi"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">Ingat saya</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Masuk ke dashboard
                            </Button>
                        </div>

                        <div className="text-muted-foreground text-center text-sm">
                            Belum memiliki akun?{' '}
                            <TextLink href={register()} tabIndex={5}>
                                Daftar sebagai pelanggan
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            {status && (
                <div className="status-pill-success mt-4 rounded-xl px-3.5 py-2.5 text-center text-xs font-semibold">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Masuk ke AD Com',
    description: 'Gunakan akunmu untuk melihat pesanan atau tugas.',
};

function GoogleIcon() {
    return (
        <svg aria-hidden="true" viewBox="0 0 24 24">
            <path
                fill="#4285F4"
                d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.9h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.8 3-4.3 3-7.4Z"
            />
            <path
                fill="#34A853"
                d="M12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.2H3.1v2.6A10 10 0 0 0 12 22Z"
            />
            <path
                fill="#FBBC05"
                d="M6.4 13.9a6 6 0 0 1 0-3.8V7.5H3.1a10 10 0 0 0 0 9l3.3-2.6Z"
            />
            <path
                fill="#EA4335"
                d="M12 5.9c1.5 0 2.8.5 3.8 1.5l2.9-2.9A9.7 9.7 0 0 0 12 2a10 10 0 0 0-8.9 5.5l3.3 2.6C7.2 7.7 9.4 5.9 12 5.9Z"
            />
        </svg>
    );
}

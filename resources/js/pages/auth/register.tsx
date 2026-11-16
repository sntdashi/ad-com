import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import OtpVerificationPanel from '@/components/otp-verification-panel';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
    registrationOtpEnabled?: boolean;
};

export default function Register({
    passwordRules,
    registrationOtpEnabled = false,
}: Props) {
    const [phone, setPhone] = useState('');
    const [otpChallengeId, setOtpChallengeId] = useState('');

    return (
        <>
            <Head title="Daftar akun" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        {registrationOtpEnabled && (
                            <input
                                type="hidden"
                                name="otp_challenge_id"
                                value={otpChallengeId}
                            />
                        )}
                        <div className="grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama lengkap</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Nama lengkap"
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-1"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Alamat email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="phone">Nomor WhatsApp</Label>
                                <Input
                                    id="phone"
                                    type="tel"
                                    required
                                    tabIndex={3}
                                    autoComplete="tel"
                                    name="phone"
                                    value={phone}
                                    onChange={(e) => {
                                        setPhone(e.target.value);
                                        setOtpChallengeId('');
                                    }}
                                    placeholder="081234567890"
                                />
                                <InputError message={errors.phone} />
                            </div>

                            {registrationOtpEnabled && (
                                <div className="grid gap-2">
                                    <OtpVerificationPanel
                                        purpose="registration_phone"
                                        phone={phone}
                                        onVerified={(id) =>
                                            setOtpChallengeId(id)
                                        }
                                    />
                                    <InputError message={errors.otp} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="password">Kata sandi</Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Buat kata sandi"
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Konfirmasi kata sandi
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={5}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Ulangi kata sandi"
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={6}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Buat akun AD Com
                            </Button>
                        </div>

                        <div className="text-muted-foreground text-center text-sm">
                            Sudah memiliki akun?{' '}
                            <TextLink href={login()} tabIndex={7}>
                                Masuk sekarang
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Daftar akun AD Com',
    description:
        'Buat akun pelanggan untuk mengajukan pesanan dan melacak servis.',
};

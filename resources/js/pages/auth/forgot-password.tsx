import { Form, Head } from '@inertiajs/react';
import { CircleCheck, KeyRound, LoaderCircle, Mail } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Lupa kata sandi" />

            {status && (
                <div className="status-pill-success mb-5 flex items-start gap-3 rounded-2xl p-4">
                    <CircleCheck className="mt-0.5 size-4 shrink-0" />
                    <p className="text-xs leading-5 font-semibold">{status}</p>
                </div>
            )}

            <div className="space-y-6">
                <div className="border-border bg-primary/8 flex items-start gap-3 rounded-2xl border p-4">
                    <span className="bg-primary grid size-9 shrink-0 place-items-center rounded-xl text-white">
                        <Mail className="size-4" />
                    </span>
                    <p className="text-muted-foreground text-xs leading-5">
                        Masukkan email akun. Tautan reset dikirim ke email dan
                        kode OTP dikirim ke WhatsApp terdaftar. Keduanya
                        diperlukan untuk membuat kata sandi baru.
                    </p>
                </div>

                <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Alamat email akun</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    autoFocus
                                    placeholder="email@example.com"
                                />

                                <InputError message={errors.email} />
                            </div>

                            <div className="mt-5 flex items-center justify-start">
                                <Button
                                    className="h-11 w-full rounded-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && (
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                    )}
                                    <KeyRound /> Kirim instruksi reset
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="text-muted-foreground space-x-1 text-center text-sm">
                    <span>Sudah ingat password?</span>
                    <TextLink href={login()}>Kembali masuk</TextLink>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Pulihkan akun AD Com',
    description: 'Reset password berlaku untuk customer dan seluruh tim.',
};

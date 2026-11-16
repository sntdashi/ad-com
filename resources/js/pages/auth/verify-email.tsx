import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Verifikasi email" />

            {status === 'verification-link-sent' && (
                <div className="status-pill-success mb-5 rounded-xl px-4 py-3 text-center text-xs font-semibold">
                    Tautan verifikasi baru telah dikirim ke alamat email yang
                    terdaftar pada akunmu.
                </div>
            )}

            <Form {...send.form()} className="space-y-5 text-center">
                {({ processing }) => (
                    <>
                        <Button
                            disabled={processing}
                            variant="default"
                            className="w-full"
                        >
                            {processing && <Spinner />}
                            Kirim ulang email verifikasi
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Keluar dari akun
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verifikasi alamat email',
    description:
        'Klik tautan verifikasi yang sudah kami kirim ke emailmu untuk melanjutkan.',
};

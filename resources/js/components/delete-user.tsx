import { Form } from '@inertiajs/react';
import { AlertTriangle, Trash2 } from 'lucide-react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <section className="liquid-panel rounded-[28px] border-red-200/70 p-5 sm:p-7">
            <div className="flex items-start gap-4">
                <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-red-100 text-red-600">
                    <AlertTriangle className="size-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-[10px] font-black tracking-[.14em] text-red-600 uppercase">
                        Zona berbahaya
                    </p>
                    <h2 className="text-ink mt-1 text-lg font-black">
                        Hapus akun customer
                    </h2>
                    <p className="text-ink/45 mt-2 max-w-xl text-xs leading-5">
                        Akun dan data yang terkait akan dihapus permanen.
                        Riwayat operasional tetap mengikuti kebijakan audit AD
                        Com.
                    </p>
                </div>
                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            className="shrink-0 rounded-full"
                            data-test="delete-user-button"
                        >
                            <Trash2 /> Hapus akun
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Yakin ingin menghapus akun?</DialogTitle>
                        <DialogDescription>
                            Tindakan ini tidak dapat dibatalkan. Masukkan kata
                            sandi untuk mengonfirmasi penghapusan permanen
                            akunmu.
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            Kata sandi
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            ref={passwordInput}
                                            placeholder="Kata sandi saat ini"
                                            autoComplete="current-password"
                                        />

                                        <InputError message={errors.password} />
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                Batal
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                Hapus permanen
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </section>
    );
}

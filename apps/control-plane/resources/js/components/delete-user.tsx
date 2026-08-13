import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@apperp/ui/dialog';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { AlertTriangle, Trash2 } from 'lucide-react';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-red-200/80 dark:border-red-950/60 shadow-xs space-y-4 relative overflow-hidden">
            <div className="flex items-center gap-3.5 pb-3 border-b border-red-100/60 dark:border-red-950/40">
                <AlertTriangle className="size-5 text-red-600 shrink-0" />
                <div>
                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                        Zona Berbahaya
                    </h3>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Tindakan berikut bersifat permanen dan tidak dapat dibatalkan.
                    </p>
                </div>
            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-1">
                <div className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed max-w-xl">
                    Setelah akun Anda dihapus, semua data, hak akses, dan informasi terkait akan dihapus secara permanen dari sistem PT SANATA SYSTEM.
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                            className="cursor-pointer shrink-0 font-bold text-xs"
                        >
                            <Trash2 className="size-4 mr-2" />
                            <span>Hapus Akun Permanen</span>
                        </Button>
                    </DialogTrigger>
                    <DialogContent size="compact" className="rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
                        <DialogHeader className="flex flex-row items-center gap-3 space-y-0 text-left border-b-0 p-0">
                            <Trash2 className="size-5 text-red-600 shrink-0" />
                            <DialogTitle className="text-base font-bold text-slate-900 dark:text-slate-100">
                                Hapus akun secara permanen?
                            </DialogTitle>
                        </DialogHeader>
                        <DialogDescription className="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                            Semua data akun, akses, dan informasi yang terkait akan dihapus secara permanen. Tindakan ini tidak dapat dibatalkan. Masukkan kata sandi saat ini untuk mengonfirmasi.
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="contents"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <DialogBody className="grid gap-2 py-2 px-0">
                                        <PasswordInput
                                            id="password"
                                            label="Kata Sandi Saat Ini"
                                            name="password"
                                            ref={passwordInput}
                                            autoComplete="current-password"
                                            className="rounded-xl focus:border-red-500 focus:ring-2 focus:ring-red-500/20"
                                            placeholder="••••••••"
                                        />

                                        <InputError message={errors.password} />
                                    </DialogBody>

                                    <DialogFooter className="gap-2 pt-3 border-t border-slate-100 dark:border-slate-800 bg-transparent px-0 pb-0">
                                        <DialogCancel
                                            variant="outline"
                                            size="sm"
                                            onClick={() => resetAndClearErrors()}
                                        >
                                            Batal
                                        </DialogCancel>
                                        <DialogAction
                                            type="submit"
                                            variant="destructive"
                                            size="sm"
                                            disabled={processing}
                                            data-test="confirm-delete-user-button"
                                        >
                                            Hapus Akun Permanen
                                        </DialogAction>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}


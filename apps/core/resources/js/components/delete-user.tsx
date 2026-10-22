import { Alert, AlertTitle, AlertDescription } from '@apperp/ui/alert';
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
import { Form } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Platform/Identity/Http/Controllers/ProfileController';
import { FormErrorHeader } from '@/components/form-error-header';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';

export default function DeleteUser() {
    const { wrap: wrapFormResponse1, summary: formResponse1Summary } =
        useInertiaFeedback();

    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="space-y-6">
            <h2 className="font-semibold">Tutup akun</h2>
            <div className="space-y-4">
                <Alert variant="destructive">
                    <AlertTriangle />
                    <AlertTitle>
                        Akun tidak dapat digunakan setelah ditutup
                    </AlertTitle>
                    <AlertDescription>
                        Pastikan Anda sudah menyelesaikan pekerjaan sebelum
                        melanjutkan.
                    </AlertDescription>
                </Alert>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            Tutup akun
                        </Button>
                    </DialogTrigger>
                    <DialogContent size="compact">
                        <DialogHeader>
                            <DialogTitle>Tutup akun Anda?</DialogTitle>
                            <DialogDescription>
                                Anda akan keluar dan akun ini tidak dapat
                                digunakan lagi. Masukkan kata sandi untuk
                                mengonfirmasi penutupan akun.
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            noValidate
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            /* `contents` membuat body dan footer tetap menjadi
                               anak langsung flex milik DialogContent. */
                            className="contents"
                            {...wrapFormResponse1({})}
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <>
                                        {formResponse1Summary}
                                        <FormErrorHeader errors={errors} />
                                    </>

                                    <DialogBody className="grid gap-2">
                                        <PasswordInput
                                            id="password"
                                            label="Kata sandi"
                                            name="password"
                                            ref={passwordInput}
                                            autoComplete="current-password"
                                        />

                                        <InputError message={errors.password} />
                                    </DialogBody>

                                    <DialogFooter>
                                        <DialogAction
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            data-test="confirm-delete-user-button"
                                        >
                                            Tutup akun
                                        </DialogAction>
                                        <DialogCancel
                                            onClick={() =>
                                                resetAndClearErrors()
                                            }
                                        >
                                            Batal
                                        </DialogCancel>
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

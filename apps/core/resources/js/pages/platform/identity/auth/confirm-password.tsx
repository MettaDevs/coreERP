import { Form, Head } from '@inertiajs/react';
import { FieldError } from '@apperp/ui/field';
import PasswordInput from '@/components/password-input';
import { Button } from '@apperp/ui/button';
import { Spinner } from '@apperp/ui/spinner';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <>
            <Head title="Konfirmasi kata sandi" />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <PasswordInput
                                id="password"
                                label="Kata sandi"
                                name="password"
                                aria-invalid={Boolean(errors.password)}
                                aria-describedby={
                                    errors.password
                                        ? 'password-error'
                                        : undefined
                                }
                                autoComplete="current-password"
                                required
                                autoFocus
                            />

                            <FieldError id="password-error">
                                {errors.password}
                            </FieldError>
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                Konfirmasi kata sandi
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Konfirmasi kata sandi',
    description: 'Konfirmasi identitas Anda sebelum melanjutkan tindakan ini.',
};

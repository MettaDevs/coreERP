import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { FormErrorHeader } from '@/components/form-error-header';
import { Form, Head } from '@inertiajs/react';
import { FieldError } from '@apperp/ui/field';
import PasswordInput from '@/components/password-input';
import { Button } from '@apperp/ui/button';
import { Spinner } from '@apperp/ui/spinner';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const { wrap: wrapFormResponse1, summary: formResponse1Summary } =
        useInertiaFeedback();

    return (
        <>
            <Head title="Konfirmasi kata sandi" />

            <Form
                noValidate
                {...store.form()}
                resetOnSuccess={['password']}
                {...wrapFormResponse1({})}
            >
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <>
                            {formResponse1Summary}
                            <FormErrorHeader errors={errors} />
                        </>

                        <div
                            className="grid gap-2"
                            data-error-field={'password'}
                        >
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

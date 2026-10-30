import { Button } from '@apperp/ui/button';
import { FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Form, Head } from '@inertiajs/react';
import { FormErrorHeader } from '@/components/form-error-header';
import PasswordInput from '@/components/password-input';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    const { wrap: wrapFormResponse1, summary: formResponse1Summary } =
        useInertiaFeedback();

    return (
        <>
            <Head title="Atur ulang kata sandi" />

            <Form
                noValidate
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                options={wrapFormResponse1({})}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <>
                            {formResponse1Summary}
                            <FormErrorHeader errors={errors} />
                        </>

                        <div className="grid gap-2" data-error-field={'email'}>
                            <Input
                                id="email"
                                label="Email"
                                type="email"
                                name="email"
                                aria-invalid={Boolean(errors.email)}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                                autoComplete="email"
                                value={email}
                                className="mt-1 block w-full"
                                readOnly
                            />
                            <FieldError id="email-error">
                                {errors.email}
                            </FieldError>
                        </div>

                        <div
                            className="grid gap-2"
                            data-error-field={'password'}
                        >
                            <PasswordInput
                                id="password"
                                label="Kata sandi baru"
                                name="password"
                                aria-invalid={Boolean(errors.password)}
                                aria-describedby={
                                    errors.password
                                        ? 'password-error'
                                        : undefined
                                }
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                passwordrules={passwordRules}
                                required
                            />
                            <FieldError id="password-error">
                                {errors.password}
                            </FieldError>
                        </div>

                        <div
                            className="grid gap-2"
                            data-error-field={'password_confirmation'}
                        >
                            <PasswordInput
                                id="password_confirmation"
                                label="Ulangi kata sandi baru"
                                name="password_confirmation"
                                aria-invalid={Boolean(
                                    errors.password_confirmation,
                                )}
                                aria-describedby={
                                    errors.password_confirmation
                                        ? 'password_confirmation-error'
                                        : undefined
                                }
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                passwordrules={passwordRules}
                                required
                            />
                            <FieldError id="password_confirmation-error">
                                {errors.password_confirmation}
                            </FieldError>
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            Atur ulang kata sandi
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Atur ulang kata sandi',
    description: 'Buat kata sandi baru untuk akun Anda.',
};

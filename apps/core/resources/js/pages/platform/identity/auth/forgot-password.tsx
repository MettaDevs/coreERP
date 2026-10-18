import { Button } from '@apperp/ui/button';
import { FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Lupa kata sandi" />

            {status && (
                <div role="status" className="mt-4 text-sm text-success">
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
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
                                    required
                                    autoFocus
                                />

                                <FieldError id="email-error">
                                    {errors.email}
                                </FieldError>
                            </div>

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && <Spinner />}
                                    Kirim tautan pemulihan
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-x-1 text-center text-sm text-muted-foreground">
                    <span>Kembali ke</span>
                    <TextLink href={login()}>halaman masuk</TextLink>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Lupa kata sandi',
    description:
        'Masukkan email akun Anda untuk menerima tautan membuat kata sandi baru.',
};

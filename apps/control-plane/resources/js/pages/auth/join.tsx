import { Form, Head } from '@inertiajs/react';

import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type Props = { passwordRules: string };

export default function Join({ passwordRules }: Props) {
    return (
        <>
            <Head title="Join business" />
            <Form
                action="/join"
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
            >
                {({ processing, errors }) => (
                    <FieldGroup>
                        <Field data-invalid={Boolean(errors.code)}>
                            <Input
                                id="code"
                                label="Invite code"
                                name="code"
                                required
                                autoFocus
                                autoComplete="one-time-code"
                                placeholder="ABCD-EFGH-JKLM-NPQR"
                            />
                            <FieldError>{errors.code}</FieldError>
                        </Field>
                        <Field data-invalid={Boolean(errors.name)}>
                            <Input
                                id="name"
                                label="Nama lengkap"
                                name="name"
                                required
                            />
                            <FieldError>{errors.name}</FieldError>
                        </Field>
                        <Field data-invalid={Boolean(errors.email)}>
                            <Input
                                id="email"
                                label="Email"
                                name="email"
                                type="email"
                                required
                                autoComplete="email"
                            />
                            <FieldError>{errors.email}</FieldError>
                        </Field>
                        <Field data-invalid={Boolean(errors.password)}>
                            <PasswordInput
                                id="password"
                                label="Password"
                                name="password"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <FieldError>{errors.password}</FieldError>
                        </Field>
                        <Field
                            data-invalid={Boolean(errors.password_confirmation)}
                        >
                            <PasswordInput
                                id="password_confirmation"
                                label="Konfirmasi password"
                                name="password_confirmation"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <FieldError>
                                {errors.password_confirmation}
                            </FieldError>
                        </Field>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Join business
                        </Button>
                        <p className="text-center text-sm text-muted-foreground">
                            Sudah punya akun?{' '}
                            <TextLink href="/login">Log in</TextLink>
                        </p>
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}

Join.layout = {
    title: 'Join dengan invite code',
    description:
        'Email wajib sebagai identity login. Pengiriman email akan hadir kemudian.',
};

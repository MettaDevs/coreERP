import { Button } from '@apperp/ui/button';
import { Field, FieldError, FieldGroup } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Form, Head } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';

type Props = { passwordRules: string; authenticated: boolean };

/**
 * Satu halaman, dua bentuk — dan yang membedakannya apakah pembacanya sudah punya akun.
 *
 * Orang yang sudah masuk hanya melihat kolom kode. Nama, email, dan kata sandinya tidak diminta,
 * karena akunnya sudah ada dan undangan tidak berhak menyentuh satu pun di antaranya. Menampilkan
 * kolom itu lalu mengabaikan isinya jauh lebih buruk daripada tidak menampilkannya: orang akan
 * mengetik kata sandi barunya di sana, menekan kirim, lalu mengira sandinya sudah berganti.
 */
export default function Join({ passwordRules, authenticated }: Props) {
    return (
        <>
            <Head title="Daftar dengan kode akses" />
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
                                label="Kode akses"
                                name="code"
                                required
                                autoFocus
                                autoComplete="one-time-code"
                                placeholder="ABCD-EFGH-JKLM-NPQR"
                            />
                            <FieldError>{errors.code}</FieldError>
                        </Field>
                        {!authenticated && (
                            <>
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
                                    data-invalid={Boolean(
                                        errors.password_confirmation,
                                    )}
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
                            </>
                        )}
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {authenticated
                                ? 'Tukarkan kode'
                                : 'Masuk ke bisnis'}
                        </Button>
                        {!authenticated && (
                            <p className="text-center text-sm text-muted-foreground">
                                Sudah punya akun?{' '}
                                <TextLink href="/login">Masuk</TextLink>
                            </p>
                        )}
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}

Join.layout = {
    title: 'Daftar dengan kode akses',
    description:
        'Masukkan kode dari admin. Role dan akses organisasi akan diterapkan otomatis, lalu Anda langsung masuk.',
};

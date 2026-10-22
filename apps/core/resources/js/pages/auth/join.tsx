import { Button } from '@apperp/ui/button';
import { Field, FieldError, FieldGroup } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Form, Head } from '@inertiajs/react';
import { FormErrorHeader } from '@/components/form-error-header';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';

type Props = {
    passwordRules: string;
    authenticated: boolean;
    mode: 'kata-sandi' | 'sso';
    code: string | null;
    invitedName: string | null;
    invitedEmail: string | null;
    tenantName: string | null;
    ssoError: string | null;
};

/**
 * Satu halaman, tiga bentuk — dan yang membedakannya siapa pembacanya.
 *
 * Orang yang sudah masuk hanya melihat kolom kode. Nama, email, dan kata sandinya tidak diminta,
 * karena akunnya sudah ada dan undangan tidak berhak menyentuh satu pun di antaranya. Menampilkan
 * kolom itu lalu mengabaikan isinya jauh lebih buruk daripada tidak menampilkannya: orang akan
 * mengetik kata sandi barunya di sana, menekan kirim, lalu mengira sandinya sudah berganti.
 *
 * Bentuk ketiga — undangan yang terikat ke satu akun SSO — mengikuti aturan yang sama, dan lebih
 * keras: tidak ada satu pun kolom. Yang menentukan siapa boleh masuk adalah akun SSO yang dipakai,
 * bukan apa pun yang dapat diketik di halaman ini, jadi menyediakan kolom hanya akan mengundang
 * orang mengisi sesuatu yang pasti diabaikan.
 */
export default function Join({
    passwordRules,
    authenticated,
    mode,
    code,
    invitedName,
    invitedEmail,
    tenantName,
    ssoError,
}: Props) {
    const { wrap: wrapFormResponse1, summary: formResponse1Summary } =
        useInertiaFeedback();
    const { wrap: wrapFormResponse2, summary: formResponse2Summary } =
        useInertiaFeedback();

    if (mode === 'sso') {
        return (
            <>
                <Head title="Undangan CoreERP" />
                <FieldGroup>
                    {ssoError && (
                        <p
                            role="alert"
                            className="rounded-md border border-destructive/40 bg-red-50 px-4 py-3 text-sm text-destructive dark:bg-red-950/40 dark:text-red-200"
                        >
                            {ssoError}
                        </p>
                    )}
                    <div className="rounded-md border bg-muted/40 px-4 py-3 text-sm">
                        <p>
                            Undangan untuk{' '}
                            <strong className="font-semibold">
                                {invitedName ?? invitedEmail}
                            </strong>
                            {tenantName ? <> di {tenantName}</> : null}.
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            Masuk dengan akun SSO yang diundang. Akun CoreERP
                            dibuat otomatis, dan tidak ada kata sandi yang perlu
                            dibuat.
                        </p>
                    </div>
                    <Form
                        noValidate
                        action="/sso/gabung"
                        method="post"
                        disableWhileProcessing
                        {...wrapFormResponse1({})}
                    >
                        {({ processing, errors }) => (
                            <>
                                <>
                                    {formResponse1Summary}
                                    <FormErrorHeader errors={errors} />
                                </>

                                <input
                                    type="hidden"
                                    name="code"
                                    value={code ?? ''}
                                />
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full"
                                >
                                    {processing && <Spinner />}
                                    Masuk lewat SSO
                                </Button>
                            </>
                        )}
                    </Form>
                </FieldGroup>
            </>
        );
    }

    return (
        <>
            <Head title="Daftar dengan kode akses" />
            {ssoError && (
                <p
                    role="alert"
                    className="mb-4 rounded-md border border-destructive/40 bg-red-50 px-4 py-3 text-sm text-destructive dark:bg-red-950/40 dark:text-red-200"
                >
                    {ssoError}
                </p>
            )}
            <Form
                noValidate
                action="/join"
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                {...wrapFormResponse2({})}
            >
                {({ processing, errors }) => (
                    <FieldGroup>
                        <>
                            {formResponse2Summary}
                            <FormErrorHeader errors={errors} />
                        </>

                        <Field
                            data-invalid={Boolean(errors.code)}
                            data-error-field={'code'}
                        >
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
                                <Field
                                    data-invalid={Boolean(errors.name)}
                                    data-error-field={'name'}
                                >
                                    <Input
                                        id="name"
                                        label="Nama lengkap"
                                        name="name"
                                        required
                                    />
                                    <FieldError>{errors.name}</FieldError>
                                </Field>
                                <Field
                                    data-invalid={Boolean(errors.email)}
                                    data-error-field={'email'}
                                >
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
                                <Field
                                    data-invalid={Boolean(errors.password)}
                                    data-error-field={'password'}
                                >
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
                                    data-error-field={'password_confirmation'}
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

import { Form, Head } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@apperp/ui/button';
import { Field, FieldError, FieldGroup } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Key, UserPlus, ShieldCheck, Mail, User } from 'lucide-react';

type Props = { passwordRules: string };

export default function Join({ passwordRules }: Props) {
    return (
        <>
            <Head title="Daftar dengan Kode Akses" />

            <div className="p-3.5 rounded-xl bg-blue-50/70 dark:bg-blue-950/30 border border-blue-200/80 dark:border-blue-800/50 flex items-start gap-3 text-xs text-blue-900 dark:text-blue-200">
                <ShieldCheck className="size-4 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <span>
                    Masukkan kode unik dari administrator bisnis Anda. Hak akses dan unit organisasi akan terkonfigurasi secara otomatis setelah akun dibuat.
                </span>
            </div>

            <Form
                action="/join"
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <FieldGroup className="space-y-4">
                        <Field data-invalid={Boolean(errors.code)}>
                            <Input
                                id="code"
                                label="Kode Akses / Undangan"
                                name="code"
                                required
                                autoFocus
                                autoComplete="one-time-code"
                                placeholder="ABCD-EFGH-JKLM-NPQR"
                                className="font-mono uppercase tracking-wider text-sm"
                            />
                            <FieldError>{errors.code}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(errors.name)}>
                            <Input
                                id="name"
                                label="Nama Lengkap"
                                name="name"
                                required
                                placeholder="Nama Anda"
                            />
                            <FieldError>{errors.name}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(errors.email)}>
                            <Input
                                id="email"
                                label="Alamat Email"
                                name="email"
                                type="email"
                                required
                                autoComplete="email"
                                placeholder="nama@perusahaan.com"
                            />
                            <FieldError>{errors.email}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(errors.password)}>
                            <PasswordInput
                                id="password"
                                label="Kata Sandi"
                                name="password"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                                placeholder="••••••••"
                            />
                            <FieldError>{errors.password}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(errors.password_confirmation)}>
                            <PasswordInput
                                id="password_confirmation"
                                label="Konfirmasi Kata Sandi"
                                name="password_confirmation"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                                placeholder="••••••••"
                            />
                            <FieldError>{errors.password_confirmation}</FieldError>
                        </Field>

                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full h-11 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/25 transition-all active:scale-[0.99] mt-2"
                        >
                            {processing ? (
                                <Spinner className="mr-2" />
                            ) : (
                                <UserPlus className="size-4 mr-2" />
                            )}
                            Gabung ke Bisnis
                        </Button>

                        <p className="pt-2 text-center text-xs text-slate-500 dark:text-slate-400">
                            Sudah memiliki akun?{' '}
                            <TextLink href="/login" className="font-semibold text-blue-600 dark:text-blue-400 hover:underline">
                                Masuk di sini
                            </TextLink>
                        </p>
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}

Join.layout = {
    title: 'Daftar dengan Kode Akses',
    description: 'Gunakan kode dari admin bisnis untuk langsung bergabung ke organisasi.',
};

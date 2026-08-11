// Components
import { Form, Head } from '@inertiajs/react';
import { LoaderCircle, MailCheck, ArrowLeft } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status, email: defaultEmail }: { status?: string; email?: string }) {
    return (
        <>
            <Head title="Lupa Kata Sandi" />

            {status && (
                <div className="mb-4 p-3.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-center text-xs font-medium text-emerald-700 dark:text-emerald-300 flex items-center justify-center gap-2">
                    <MailCheck className="size-4 shrink-0" />
                    <span>{status}</span>
                </div>
            )}

            <div className="space-y-5">
                <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-1.5">
                                <Input
                                    id="email"
                                    label="Alamat Email"
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    autoFocus
                                    defaultValue={defaultEmail || ''}
                                    placeholder="nama@perusahaan.com"
                                />

                                <InputError message={errors.email} />
                            </div>

                            <div className="pt-2">
                                <Button
                                    className="w-full h-11 bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-indigo-500/40 transition-all active:scale-[0.99]"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing ? (
                                        <LoaderCircle className="h-4 w-4 animate-spin mr-2" />
                                    ) : null}
                                    Kirim Link Reset Kata Sandi
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="pt-2 text-center text-xs text-slate-500 dark:text-slate-400 space-y-3">
                    <p>
                        Sudah ingat kata sandi Anda?{' '}
                        <TextLink href={login()} className="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                            Masuk Akun
                        </TextLink>
                    </p>
                    <div className="pt-2 border-t border-slate-100 dark:border-slate-800">
                        <TextLink
                            href="/"
                            className="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 font-medium transition-colors"
                        >
                            
                        </TextLink>
                    </div>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Lupa Kata Sandi',
    description: 'Masukkan alamat email Anda untuk menerima link reset kata sandi.',
};

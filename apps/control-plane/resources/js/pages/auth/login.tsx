import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Spinner } from '@apperp/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import PasskeyVerify from '@/components/passkey-verify';
import { LogIn } from 'lucide-react';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Masuk Akun" />

            {/* Passkey Login Option */}
            <div className="rounded-xl border border-indigo-100 dark:border-indigo-900/40 bg-indigo-50/50 dark:bg-indigo-950/20 p-3.5 backdrop-blur-sm">
                <PasskeyVerify />
            </div>

            {status && (
                <div className="p-3.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-center text-xs font-medium text-emerald-700 dark:text-emerald-300">
                    {status}
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="space-y-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="space-y-4">
                            <div className="space-y-1.5">
                                <Input
                                    id="email"
                                    label="Alamat Email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="nama@perusahaan.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="space-y-1.5">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="password">Kata Sandi</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium"
                                            tabIndex={5}
                                        >
                                            Lupa kata sandi?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="••••••••"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center justify-between pt-1">
                                <div className="flex items-center space-x-2.5">
                                    <Checkbox
                                        id="remember"
                                        name="remember"
                                        tabIndex={3}
                                    />
                                    <Label
                                        htmlFor="remember"
                                        className="text-xs font-normal text-slate-600 dark:text-slate-400 cursor-pointer"
                                    >
                                        Ingat saya di perangkat ini
                                    </Label>
                                </div>
                            </div>

                            <Button
                                type="submit"
                                className="w-full h-11 bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-indigo-500/40 transition-all active:scale-[0.99] mt-2"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing ? (
                                    <Spinner className="mr-2" />
                                ) : (
                                    <LogIn className="size-4 mr-2" />
                                )}
                                Masuk ke Akun
                            </Button>
                        </div>

                        {/* Navigation Links */}
                        <div className="pt-2 text-center text-xs text-slate-500 dark:text-slate-400 space-y-2">
                            <p>
                                Belum memiliki akun bisnis?{' '}
                                <TextLink href={register()} className="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline" tabIndex={5}>
                                    Daftar Bisnis Baru
                                </TextLink>
                            </p>
                            <p>
                                Punya kode akses tim?{' '}
                                <TextLink href="/join" className="font-medium text-slate-700 dark:text-slate-300 hover:underline">
                                    Gabung dengan Kode Akses
                                </TextLink>
                            </p>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Masuk ke Akun Anda',
    description: 'Masukkan email dan kata sandi untuk mengakses platform operasional ERP.',
};

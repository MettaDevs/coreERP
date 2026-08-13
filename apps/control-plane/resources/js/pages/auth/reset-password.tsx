import { Head, useForm, Link } from '@inertiajs/react';
import { KeyRound, ArrowLeft } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';

type Props = {
    token: string;
    email: string;
};

export default function ResetPassword({ token, email }: Props) {
    const [clientErrors, setClientErrors] = useState<{
        password?: string;
        password_confirmation?: string;
    }>({});

    const form = useForm({
        token: token || '',
        email: email || '',
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const errs: { password?: string; password_confirmation?: string } = {};

        if (!form.data.password) {
            errs.password = 'Kata sandi wajib diisi.';
        }

        if (!form.data.password_confirmation) {
            errs.password_confirmation = 'Konfirmasi kata sandi wajib diisi.';
        } else if (form.data.password && form.data.password !== form.data.password_confirmation) {
            errs.password_confirmation = 'Konfirmasi kata sandi tidak cocok.';
        }

        setClientErrors(errs);
        if (Object.keys(errs).length > 0) return;

        form.post('/reset-password', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    const passwordError = form.errors.password || clientErrors.password;
    const confirmationError = form.errors.password_confirmation || clientErrors.password_confirmation;

    return (
        <>
            <Head title="Buat Kata Sandi Baru" />

            <div className="space-y-4">
                <div className="flex items-center justify-center size-12 rounded-2xl bg-[#EAFBFC] text-[#00AFC0] border border-[#00AFC0]/20 mx-auto">
                    <KeyRound className="size-6" />
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <input type="hidden" name="token" value={token} />

                    <div className="space-y-1">
                        <Input
                            id="email"
                            label="Alamat Email"
                            type="email"
                            name="email"
                            value={form.data.email}
                            readOnly
                            disabled
                            className="w-full bg-slate-100 dark:bg-slate-800/60 text-slate-500 cursor-not-allowed"
                        />
                        <InputError message={form.errors.email} />
                    </div>

                    <div className="space-y-1">
                        <PasswordInput
                            id="password"
                            label="Kata Sandi Baru"
                            name="password"
                            autoComplete="new-password"
                            autoFocus
                            value={form.data.password}
                            onChange={(e) => {
                                form.setData('password', e.target.value);
                                if (clientErrors.password) setClientErrors((prev) => ({ ...prev, password: undefined }));
                                if (form.errors.password) form.clearErrors('password');
                            }}
                            placeholder="••••••••"
                            className="w-full"
                        />
                        <InputError message={passwordError} />
                    </div>

                    <div className="space-y-1">
                        <PasswordInput
                            id="password_confirmation"
                            label="Konfirmasi Kata Sandi Baru"
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(e) => {
                                form.setData('password_confirmation', e.target.value);
                                if (clientErrors.password_confirmation) setClientErrors((prev) => ({ ...prev, password_confirmation: undefined }));
                                if (form.errors.password_confirmation) form.clearErrors('password_confirmation');
                            }}
                            placeholder="••••••••"
                            className="w-full"
                        />
                        <InputError message={confirmationError} />
                    </div>

                    <Button
                        type="submit"
                        className="w-full h-11 btn-gradient-primary cursor-pointer active:scale-[0.99] mt-1 flex items-center justify-center gap-2"
                        disabled={form.processing}
                        data-test="reset-password-button"
                    >
                        {form.processing ? (
                            <>
                                <Spinner className="mr-2 text-white" />
                                Memproses...
                            </>
                        ) : (
                            'Simpan Kata Sandi'
                        )}
                    </Button>
                </form>

                <div className="pt-3 border-t border-slate-100 dark:border-slate-800 text-center">
                    <Link
                        href="/login"
                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#00AFC0] hover:text-[#008B9B] hover:underline transition-colors cursor-pointer"
                    >
                        <ArrowLeft className="size-3.5" />
                        <span>Kembali ke Login</span>
                    </Link>
                </div>
            </div>
        </>
    );
}

ResetPassword.layout = {
    title: 'Buat Kata Sandi Baru',
    description: 'Gunakan kata sandi baru untuk mengamankan akun Anda.',
};

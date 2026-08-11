import { Head, useForm, Link } from '@inertiajs/react';
import { ShieldCheck, MailCheck, ArrowLeft, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';

type Props = {
    status?: string;
    email?: string;
};

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function ForgotPassword({ status, email: defaultEmail }: Props) {
    const [clientEmailError, setClientEmailError] = useState<string | null>(null);

    const form = useForm({
        email: defaultEmail || '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setClientEmailError(null);

        const trimmed = form.data.email.trim();
        if (!trimmed) {
            setClientEmailError('Email wajib diisi.');
            return;
        }

        if (!EMAIL_REGEX.test(trimmed)) {
            setClientEmailError('Format email tidak valid.');
            return;
        }

        form.setData('email', trimmed.toLowerCase());
        form.post('/forgot-password');
    };

    const emailDisplayError = form.errors.email || clientEmailError || undefined;

    return (
        <>
            <Head title="Reset Kata Sandi" />

            <div className="space-y-4">
                <div className="flex items-center justify-center size-12 rounded-2xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-100 dark:border-blue-900/60 mx-auto">
                    <ShieldCheck className="size-6" />
                </div>

                {status && (
                    <div className="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 text-slate-800 dark:text-slate-100 text-xs space-y-1.5 animate-in fade-in zoom-in-95">
                        <div className="flex items-center gap-2 font-bold text-emerald-700 dark:text-emerald-300 text-xs sm:text-sm">
                            <CheckCircle2 className="size-4 shrink-0 text-emerald-600" />
                            <span>Link reset password telah dikirim ke email Anda.</span>
                        </div>
                        <p className="text-emerald-600/90 dark:text-emerald-400 text-[11px] leading-relaxed">
                            Silakan periksa inbox atau folder spam email Anda, lalu klik tautan di dalamnya untuk mengatur ulang kata sandi.
                        </p>
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1">
                        <Input
                            id="email"
                            label="Alamat Email"
                            type="email"
                            name="email"
                            autoComplete="email"
                            autoFocus
                            value={form.data.email}
                            onChange={(e) => {
                                form.setData('email', e.target.value);
                                if (clientEmailError) setClientEmailError(null);
                                if (form.errors.email) form.clearErrors('email');
                            }}
                            placeholder="nama@perusahaan.com"
                            className="w-full"
                        />
                        <InputError message={emailDisplayError} />
                    </div>

                    <Button
                        type="submit"
                        className="w-full h-11 bg-blue-600 hover:bg-blue-500 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 transition-all active:scale-[0.99] mt-2 cursor-pointer"
                        disabled={form.processing}
                        data-test="email-password-reset-link-button"
                    >
                        {form.processing ? (
                            <>
                                <Spinner className="mr-2" />
                                Memproses...
                            </>
                        ) : (
                            'Kirim Link Reset'
                        )}
                    </Button>
                </form>

                <div className="pt-3 border-t border-slate-100 dark:border-slate-800 text-center">
                    <Link
                        href="/login"
                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline transition-colors cursor-pointer"
                    >
                        <ArrowLeft className="size-3.5" />
                        <span>Kembali ke Login</span>
                    </Link>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Reset Kata Sandi',
    description: 'Masukkan alamat email akun Anda untuk menerima tautan untuk mengatur ulang kata sandi.',
};

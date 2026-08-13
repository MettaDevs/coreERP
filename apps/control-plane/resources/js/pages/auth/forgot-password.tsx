import { Head, useForm, Link } from '@inertiajs/react';
import { ShieldCheck, ArrowLeft, CheckCircle2, Clock } from 'lucide-react';
import { useState, useEffect } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';

type Props = {
    status?: string;
    email?: string;
};

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const COOLDOWN_SECONDS = 30;
const STORAGE_KEY = 'forgot_password_cooldown_end';

export default function ForgotPassword({ status, email: defaultEmail }: Props) {
    const [clientEmailError, setClientEmailError] = useState<string | null>(null);
    const [countdown, setCountdown] = useState<number>(0);
    const [cooldownNotice, setCooldownNotice] = useState<boolean>(false);

    const form = useForm({
        email: defaultEmail || '',
    });

    const startCooldown = () => {
        const endTime = Date.now() + COOLDOWN_SECONDS * 1000;
        localStorage.setItem(STORAGE_KEY, endTime.toString());
        setCountdown(COOLDOWN_SECONDS);
        setCooldownNotice(false);
    };

    // 1. Initialize countdown from localStorage or status prop on mount
    useEffect(() => {
        const savedEndTime = localStorage.getItem(STORAGE_KEY);
        if (savedEndTime) {
            const remaining = Math.ceil((parseInt(savedEndTime, 10) - Date.now()) / 1000);
            if (remaining > 0) {
                setCountdown(remaining);
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
        } else if (status) {
            startCooldown();
        }
    }, [status]);

    // 2. Watch for backend throttle error and automatically start 30s countdown
    useEffect(() => {
        if (form.errors.email && (form.errors.email.includes('Harap tunggu') || form.errors.email.includes('throttled'))) {
            if (countdown === 0) {
                startCooldown();
            }
            setCooldownNotice(true);
        }
    }, [form.errors.email]);

    // 3. Realtime 1-second ticker interval
    useEffect(() => {
        if (countdown <= 0) return;

        const timer = setInterval(() => {
            setCountdown((prev) => {
                if (prev <= 1) {
                    localStorage.removeItem(STORAGE_KEY);
                    setCooldownNotice(false);
                    return 0;
                }
                return prev - 1;
            });
        }, 1000);

        return () => clearInterval(timer);
    }, [countdown]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setClientEmailError(null);

        // Intercept force click during active 30s cooldown
        if (countdown > 0) {
            setCooldownNotice(true);
            return;
        }

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
        form.post('/forgot-password', {
            onSuccess: () => {
                startCooldown();
            },
            onError: (errors) => {
                if (errors.email && (errors.email.includes('Harap tunggu') || errors.email.includes('throttled'))) {
                    startCooldown();
                    setCooldownNotice(true);
                }
            },
        });
    };

    const isThrottledError = form.errors.email && (form.errors.email.includes('Harap tunggu') || form.errors.email.includes('throttled'));
    const emailDisplayError = isThrottledError ? (clientEmailError || undefined) : (form.errors.email || clientEmailError || undefined);

    return (
        <>
            <Head title="Reset Kata Sandi" />

            <div className="space-y-4">
                <div className="flex items-center justify-center size-12 rounded-2xl bg-[#C8F1F5]/80 dark:bg-cyan-950/80 text-[#007C89] dark:text-cyan-300 border border-[#08BFC3]/40 dark:border-cyan-500/60 mx-auto shadow-sm dark:shadow-[0_0_15px_rgba(0,201,200,0.25)]">
                    <ShieldCheck className="size-6 text-[#007C89] dark:text-cyan-300" />
                </div>

                {/* Status Message from Server */}
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

                {/* Realtime Cooldown Notice (Triggered on Force Click during Cooldown) */}
                {countdown > 0 && cooldownNotice && (
                    <div className="p-3.5 rounded-2xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200/80 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs space-y-1 animate-in fade-in zoom-in-95">
                        <div className="flex items-center gap-2 font-bold text-amber-800 dark:text-amber-300 text-xs">
                            <Clock className="size-4 shrink-0 text-amber-600 animate-spin" />
                            <span>Tunggu Cooldown Reset Password</span>
                        </div>
                        <p className="text-amber-700 dark:text-amber-400 text-[11px] leading-relaxed">
                            Mohon tunggu <strong className="font-extrabold text-amber-900 dark:text-amber-100 underline decoration-amber-500">{countdown} detik</strong> lagi sebelum mencoba mengirim ulang link reset.
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
                        className="w-full h-11 rounded-full btn-gradient-primary text-white font-extrabold text-xs sm:text-sm cursor-pointer active:scale-[0.99] mt-1 flex items-center justify-center gap-2 border-none transition-all"
                        disabled={form.processing}
                        data-test="email-password-reset-link-button"
                    >
                        {form.processing ? (
                            <>
                                <Spinner className="mr-2 text-white" />
                                Memproses...
                            </>
                        ) : countdown > 0 ? (
                            <>
                                <Clock className="size-4 text-white animate-spin" />
                                <span>Tunggu {countdown} Detik...</span>
                            </>
                        ) : (
                            'Kirim Link Reset'
                        )}
                    </Button>
                </form>

                <div className="pt-3 border-t border-slate-100 dark:border-slate-800 text-center">
                    <Link
                        href="/login"
                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#007C89] hover:text-[#08BFC3] hover:underline transition-colors cursor-pointer"
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

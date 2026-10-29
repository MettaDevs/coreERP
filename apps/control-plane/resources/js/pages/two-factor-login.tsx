import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { Button } from '@apperp/ui/button';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function TwoFactorLogin() {
    const { data, setData, post, processing, errors } = useForm({ code: '' });
    const { wrap, summary } = useInertiaFeedback(
        'Verifikasi belum dapat diselesaikan',
    );
    function submit(event: FormEvent) {
        event.preventDefault();
        post(
            '/login/verifikasi',
            wrap({
                onSuccess: () => setData('code', ''),
                onError: () => setData('code', ''),
            }),
        );
    }

    return (
        <main className="flex min-h-svh items-center justify-center bg-muted/30 px-4 text-foreground">
            <Head title="Verifikasi akun" />
            <form
                noValidate
                onSubmit={submit}
                className="w-full max-w-md space-y-4 rounded-xl border bg-card p-6"
            >
                <h1 className="text-lg font-semibold">Verifikasi akun Anda</h1>
                <p className="text-sm text-muted-foreground">
                    Masukkan kode dari aplikasi autentikator akun Core Anda,
                    atau gunakan satu kode pemulihan. Verifikasi berlaku selama
                    lima menit.
                </p>
                {summary}
                <FormErrorHeader errors={errors} />
                <FormControl
                    error={
                        errors.code && (
                            <span id="verification-error">{errors.code}</span>
                        )
                    }
                >
                    <Input
                        id="verification-code"
                        label="Kode verifikasi atau pemulihan"
                        required
                        autoFocus
                        autoCapitalize="none"
                        spellCheck={false}
                        autoComplete="one-time-code"
                        value={data.code}
                        onChange={(event) =>
                            setData('code', event.target.value)
                        }
                        aria-invalid={!!errors.code}
                        aria-describedby={
                            errors.code ? 'verification-error' : undefined
                        }
                    />
                </FormControl>
                <div className="flex items-center gap-4">
                    <Button
                        type="submit"
                        disabled={processing}
                        aria-busy={processing}
                    >
                        {processing ? 'Memeriksa…' : 'Verifikasi dan masuk'}
                    </Button>
                    <Link href="/login" className="text-sm underline">
                        Kembali ke login
                    </Link>
                </div>
            </form>
        </main>
    );
}

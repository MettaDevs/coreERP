import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { Button } from '@apperp/ui/button';
import { Card, CardHeader, CardTitle, CardContent } from '@apperp/ui/card';
import { Input } from '@apperp/ui/input';
import { Head, useForm } from '@inertiajs/react';
import { Link2, Unlink, ShieldCheck, CircleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import Shell from '@/components/shell';

type SsoState = {
    linked: boolean;
    emailAtLink: string | null;
    linkedAt: string | null;
};

type Props = {
    sso: SsoState | null;
    ssoError: string | null;
};

/**
 * Akun operator yang sedang masuk. Hari ini isinya hanya satu hal: hubungan dengan akun SSO.
 *
 * Kata sandi diketik di sini, di setiap tindakan — bukan di halaman konfirmasi terpisah seperti
 * Core. Konsol tidak punya halaman itu, dan satu kolom di samping tombolnya lebih jelas menyatakan
 * bahwa tindakan inilah yang meminta kata sandi.
 */
export default function Account({ sso, ssoError }: Props) {
    const { wrap: wrapLocalForm0Request, summary: localForm0RequestSummary } =
        useInertiaFeedback();

    const {
        data,
        setData,
        post,
        delete: destroy,
        processing,
        errors,
        reset,
    } = useForm({ password: '' });

    function submit(e: FormEvent) {
        e.preventDefault();

        if (sso?.linked) {
            destroy(
                '/akun/sso',
                wrapLocalForm0Request({ onFinish: () => reset('password') }),
            );
        } else {
            post(
                '/akun/sso',
                wrapLocalForm0Request({ onError: () => reset('password') }),
            );
        }
    }

    return (
        <Shell title="Akun" description="Cara Anda masuk ke konsol operator.">
            <Head title="Akun" />

            <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                <Card>
                    <CardHeader>
                        <CardTitle>Masuk dengan SSO</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="flex items-start gap-3 rounded-md bg-muted/40 p-4">
                            <ShieldCheck
                                className={`mt-0.5 size-5 shrink-0 ${sso?.linked ? 'text-success' : 'text-muted-foreground'}`}
                            />
                            <div className="min-w-0">
                                <p
                                    className="text-sm font-medium"
                                    data-test="sso-status"
                                >
                                    {sso === null
                                        ? 'SSO belum tersedia'
                                        : sso.linked
                                          ? 'Akun SSO terhubung'
                                          : 'Akun SSO belum terhubung'}
                                </p>
                                {sso?.linked && (
                                    <dl className="mt-3 space-y-2 text-sm">
                                        {sso.emailAtLink && (
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Email saat dihubungkan
                                                </dt>
                                                <dd className="break-all">
                                                    {sso.emailAtLink}
                                                </dd>
                                            </div>
                                        )}
                                        {sso.linkedAt && (
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Dihubungkan
                                                </dt>
                                                <dd>{sso.linkedAt}</dd>
                                            </div>
                                        )}
                                    </dl>
                                )}
                                {!sso?.linked && (
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {sso === null
                                            ? 'SSO perlu disetel oleh pengelola konsol sebelum dapat dihubungkan.'
                                            : 'Hubungkan akun untuk menggunakan SSO saat masuk berikutnya.'}
                                    </p>
                                )}
                            </div>
                        </div>
                        {ssoError && (
                            <div
                                role="alert"
                                data-test="sso-error"
                                className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
                            >
                                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                {ssoError}
                            </div>
                        )}
                        {sso !== null && (
                            <form
                                noValidate
                                onSubmit={submit}
                                className="space-y-4 border-t pt-5"
                            >
                                {localForm0RequestSummary}
                                <FormErrorHeader errors={errors} />
                                <p className="text-sm font-medium">
                                    {sso.linked
                                        ? 'Putuskan hubungan akun'
                                        : 'Konfirmasi untuk menghubungkan akun'}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {sso.linked
                                        ? 'Setelah diputus, akun SSO ini tidak dapat dipakai masuk ke konsol Anda.'
                                        : 'Setelah konfirmasi, Anda akan diarahkan ke layanan SSO untuk memilih akun.'}
                                </p>
                                <div className="max-w-sm space-y-2">
                                    <Input
                                        id="password"
                                        label="Kata sandi konsol Anda"
                                        required
                                        type="password"
                                        autoComplete="current-password"
                                        value={data.password}
                                        onChange={(e) =>
                                            setData('password', e.target.value)
                                        }
                                        aria-invalid={Boolean(errors.password)}
                                        aria-describedby={
                                            errors.password
                                                ? 'password-error'
                                                : undefined
                                        }
                                    />
                                    {errors.password && (
                                        <p
                                            id="password-error"
                                            className="text-sm text-destructive"
                                        >
                                            {errors.password}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    variant={
                                        sso.linked ? 'destructive' : 'default'
                                    }
                                    disabled={processing}
                                    data-test={
                                        sso.linked
                                            ? 'sso-disconnect-button'
                                            : 'sso-connect-button'
                                    }
                                >
                                    {sso.linked ? (
                                        <Unlink className="size-4" />
                                    ) : (
                                        <Link2 className="size-4" />
                                    )}
                                    {processing
                                        ? 'Memproses...'
                                        : sso.linked
                                          ? 'Putuskan SSO'
                                          : 'Hubungkan SSO'}
                                </Button>
                            </form>
                        )}
                    </CardContent>
                </Card>
                <aside className="space-y-3 p-1 text-sm text-muted-foreground">
                    <h2 className="font-semibold text-foreground">
                        Akun yang bisa dipakai masuk
                    </h2>
                    <p>
                        Hanya akun SSO yang Anda hubungkan di halaman ini yang
                        dapat dipakai masuk. Email yang sama saja tidak memberi
                        akses.
                    </p>
                    <p>
                        Kata sandi konsol diperlukan untuk memastikan perubahan
                        ini dilakukan oleh Anda.
                    </p>
                </aside>
            </div>
        </Shell>
    );
}

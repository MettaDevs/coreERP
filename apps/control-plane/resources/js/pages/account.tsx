import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Head, useForm } from '@inertiajs/react';
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
            destroy('/akun/sso', { onFinish: () => reset('password') });
        } else {
            post('/akun/sso', { onError: () => reset('password') });
        }
    }

    return (
        <Shell title="Akun" description="Cara Anda masuk ke konsol operator.">
            <Head title="Akun" />

            <section className="max-w-xl space-y-4 rounded-lg border bg-background p-6">
                <div>
                    <h2 className="text-base font-semibold">Akun SSO</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Hubungkan akun SSO supaya berikutnya Anda dapat masuk
                        lewat SSO. Hanya akun yang Anda hubungkan sendiri dari
                        sini yang dapat dipakai — kesamaan email tidak pernah
                        cukup.
                    </p>
                </div>

                {ssoError && (
                    <p
                        className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                        data-test="sso-error"
                    >
                        {ssoError}
                    </p>
                )}

                {sso === null ? (
                    <p className="text-sm text-muted-foreground">
                        SSO belum disetel di konsol ini.
                    </p>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <p className="text-sm" data-test="sso-status">
                            {sso.linked
                                ? `Terhubung${sso.emailAtLink ? ` sebagai ${sso.emailAtLink}` : ''}${sso.linkedAt ? `, ${sso.linkedAt}` : ''}.`
                                : 'Belum terhubung.'}
                        </p>

                        <div className="space-y-2">
                            <Label htmlFor="password">
                                Kata sandi konsol Anda
                            </Label>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="current-password"
                                value={data.password}
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                            />
                            {errors.password && (
                                <p className="text-sm text-destructive">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <Button
                            type="submit"
                            variant={sso.linked ? 'destructive' : 'default'}
                            disabled={processing}
                            data-test={
                                sso.linked
                                    ? 'sso-disconnect-button'
                                    : 'sso-connect-button'
                            }
                        >
                            {sso.linked ? 'Putuskan SSO' : 'Hubungkan SSO'}
                        </Button>
                    </form>
                )}
            </section>
        </Shell>
    );
}

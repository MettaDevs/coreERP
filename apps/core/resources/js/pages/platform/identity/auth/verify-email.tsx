import { Button } from '@apperp/ui/button';
import { Spinner } from '@apperp/ui/spinner';
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Verifikasi email" />

            {status === 'verification-link-sent' && (
                <div role="status" className="mt-4 text-sm text-success">
                    Tautan verifikasi baru sudah dikirim ke email yang Anda
                    gunakan saat mendaftar.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            Kirim ulang email verifikasi
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Keluar
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verifikasi email',
    description:
        'Buka email yang kami kirim, lalu klik tautan verifikasi untuk melanjutkan.',
};

import { Button } from '@apperp/ui/button';
import { Spinner } from '@apperp/ui/spinner';
import { Form, Head } from '@inertiajs/react';
import { FormErrorHeader } from '@/components/form-error-header';
import TextLink from '@/components/text-link';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    const { wrap: wrapFormResponse1, summary: formResponse1Summary } =
        useInertiaFeedback();

    return (
        <>
            <Head title="Verifikasi email" />

            {status === 'verification-link-sent' && (
                <div role="status" className="mt-4 text-sm text-success">
                    Tautan verifikasi baru sudah dikirim ke email yang Anda
                    gunakan saat mendaftar.
                </div>
            )}

            <Form
                noValidate
                {...send.form()}
                className="space-y-6 text-center"
                {...wrapFormResponse1({})}
            >
                {({ processing, errors }) => (
                    <>
                        <>
                            {formResponse1Summary}
                            <FormErrorHeader errors={errors} />
                        </>

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

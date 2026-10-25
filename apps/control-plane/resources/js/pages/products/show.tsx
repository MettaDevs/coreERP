import { FormErrorHeader } from '@/components/form-error-header';
import Shell from '@/components/shell';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { statusLabels } from './types';
import type { Product, ProductTenant } from './types';

export default function Show({
    tenant,
    products,
}: {
    tenant: ProductTenant;
    products: Product[];
}) {
    const { errors } = usePage().props;
    const { wrap, summary } = useInertiaFeedback();
    const [retrying, setRetrying] = useState(false);
    const [showCredentials, setShowCredentials] = useState(false);
    const [copyMessage, setCopyMessage] = useState('');
    const profileName =
        products
            .find((item) => item.code === tenant.product)
            ?.profiles.find((item) => item.code === tenant.profile)?.name ??
        tenant.profile;
    const { start, stop } = usePoll(
        5000,
        { only: ['tenant'] },
        {
            autoStart: false,
        },
    );
    useEffect(() => {
        if (tenant.status === 'queued' || tenant.status === 'provisioning')
            start();
        else stop();
        return stop;
    }, [tenant.status, start, stop]);

    async function copyOwner() {
        if (!tenant.credentials) return;
        try {
            await navigator.clipboard.writeText(
                `Alamat: ${tenant.public_url ?? ''}\nPengguna: ${tenant.credentials.username}\nKata sandi: ${tenant.credentials.password}`,
            );
            setCopyMessage('Akun admin fasilitas disalin.');
        } catch {
            setCopyMessage(
                'Belum dapat menyalin. Pilih teks akun admin fasilitas lalu salin secara manual.',
            );
        }
    }

    function retry() {
        setRetrying(true);
        router.post(
            `/produk/tenant/${tenant.id}/ulang`,
            {},
            wrap({ onFinish: () => setRetrying(false) }),
        );
    }

    return (
        <Shell title={tenant.name}>
            <Head title={tenant.name} />
            <Link href="/produk/tenant" className="text-sm underline">
                Kembali ke tenant produk
            </Link>
            {summary}
            <FormErrorHeader errors={errors} />
            <Card>
                <CardHeader>
                    <CardTitle>{tenant.name}</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">Status</dt>
                            <dd>{statusLabels[tenant.status]}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Jenis fasilitas
                            </dt>
                            <dd>{profileName}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Admin fasilitas
                            </dt>
                            <dd>
                                {tenant.owner_name} ({tenant.owner_email})
                            </dd>
                        </div>
                    </dl>
                    {tenant.failure_message && (
                        <p role="alert" className="text-sm text-destructive">
                            {tenant.failure_message}
                        </p>
                    )}
                    {tenant.status === 'failed' && (
                        <Button disabled={retrying} onClick={retry}>
                            {retrying
                                ? 'Meminta penyiapan…'
                                : 'Coba siapkan kembali'}
                        </Button>
                    )}
                    {tenant.status === 'ready' && tenant.public_url && (
                        <Button asChild>
                            <a
                                href={tenant.public_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Buka aplikasi
                            </a>
                        </Button>
                    )}
                    {tenant.status === 'ready' && tenant.credentials && (
                        <div className="space-y-3">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setShowCredentials(!showCredentials)
                                }
                            >
                                {showCredentials
                                    ? 'Sembunyikan akun awal'
                                    : 'Lihat akun awal'}
                            </Button>
                            {showCredentials && (
                                <div className="space-y-2 text-sm">
                                    <Button
                                        variant="outline"
                                        onClick={() => void copyOwner()}
                                    >
                                        Salin akun admin fasilitas
                                    </Button>
                                    {copyMessage && (
                                        <p role="status">{copyMessage}</p>
                                    )}
                                    <p>
                                        Admin fasilitas:{' '}
                                        <code>
                                            {tenant.credentials.username}
                                        </code>
                                    </p>
                                    <p>
                                        Kata sandi awal:{' '}
                                        <code>
                                            {tenant.credentials.password}
                                        </code>
                                    </p>
                                    <p>
                                        Akun dukungan Metta:{' '}
                                        <code>
                                            {
                                                tenant.credentials
                                                    .operator_username
                                            }
                                        </code>
                                    </p>
                                    <p>
                                        Kata sandi dukungan:{' '}
                                        <code>
                                            {
                                                tenant.credentials
                                                    .operator_password
                                            }
                                        </code>
                                    </p>
                                    <p className="text-muted-foreground">
                                        Sampaikan hanya akun admin fasilitas
                                        kepada pengelola fasilitas. Simpan akun
                                        dukungan untuk operator Metta.
                                    </p>
                                </div>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>
        </Shell>
    );
}

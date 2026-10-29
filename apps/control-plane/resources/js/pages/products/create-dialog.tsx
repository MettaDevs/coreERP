import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { ActionButton } from '@apperp/ui/action-button';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import {
    PageDialog,
    PageDialogContent,
    PageDialogTrigger,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { useConfirmation } from '@apperp/ui/use-confirmation';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { Product } from './types';

export default function CreateDialog({ products }: { products: Product[] }) {
    const [open, setOpen] = useState(false);
    const { wrap, summary } = useInertiaFeedback();
    const { confirm, confirmation } = useConfirmation();
    const form = useForm({
        product: products[0]?.code ?? '',
        profile: '',
        name: '',
        slug: '',
        owner_name: '',
        owner_email: '',
        idempotency_key: '',
    });
    const product = products.find((item) => item.code === form.data.product);
    const profile = product?.profiles.find(
        (item) => item.code === form.data.profile,
    );

    async function close() {
        if (form.processing) {
            return;
        }

        if (
            form.isDirty &&
            !(await confirm({
                title: 'Buang perubahan?',
                description: 'Isian tenant yang belum disimpan akan dibuang.',
                confirmLabel: 'Buang perubahan',
                destructive: true,
            }))
        ) {
            return;
        }

        form.reset();
        form.clearErrors();
        setOpen(false);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(
            '/produk/tenant',
            wrap({
                onSuccess: () => {
                    setOpen(false);
                    form.reset();
                },
            }),
        );
    }

    return (
        <PageDialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    form.setData('idempotency_key', crypto.randomUUID());
                    setOpen(true);
                } else {
                    void close();
                }
            }}
        >
            {confirmation}
            <PageDialogTrigger asChild>
                <ActionButton
                    action="create"
                    disabled={products.length === 0}
                />
            </PageDialogTrigger>
            <PageDialogContent
                title="Tenant produk"
                recordTitle="Baru"
                busy={form.processing}
            >
                <form
                    noValidate
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <PageDialogToolbar>
                        <ActionButton
                            action="save"
                            type="submit"
                            pending={form.processing}
                        />
                        <ActionButton
                            action="cancel"
                            disabled={form.processing}
                            onClick={() => void close()}
                        />
                    </PageDialogToolbar>
                    <PageDialogBody>
                        {summary}
                        <FormErrorHeader errors={form.errors} />
                        <div className="space-y-6 py-4">
                            <FormGrid>
                                <FormControl error={form.errors.product}>
                                    <NativeSelect
                                        id="product"
                                        label="Produk"
                                        value={form.data.product}
                                        onChange={(event) =>
                                            form.setData({
                                                ...form.data,
                                                product: event.target.value,
                                                profile: '',
                                            })
                                        }
                                        required
                                    >
                                        {products.map((item) => (
                                            <option
                                                key={item.code}
                                                value={item.code}
                                            >
                                                {item.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormControl>
                                <FormControl
                                    error={form.errors.profile}
                                    hint={profile?.description}
                                >
                                    <NativeSelect
                                        id="profile"
                                        label="Jenis fasilitas"
                                        value={form.data.profile}
                                        onChange={(event) =>
                                            form.setData(
                                                'profile',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    >
                                        <option value="">
                                            Pilih jenis fasilitas
                                        </option>
                                        {product?.profiles.map((item) => (
                                            <option
                                                key={item.code}
                                                value={item.code}
                                            >
                                                {item.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormControl>
                                <FormControl
                                    error={form.errors.name}
                                    hint="Nama ini digunakan pada UnitBisnis, header, dan judul browser."
                                >
                                    <Input
                                        id="name"
                                        label="Nama fasilitas"
                                        required
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    error={form.errors.slug}
                                    hint="Gunakan huruf kecil, angka, atau tanda hubung untuk alamat fasilitas."
                                >
                                    <Input
                                        id="slug"
                                        label="Nama alamat tenant"
                                        required
                                        value={form.data.slug}
                                        onChange={(event) =>
                                            form.setData(
                                                'slug',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormControl>
                                <FormControl error={form.errors.owner_name}>
                                    <Input
                                        id="owner_name"
                                        label="Nama admin fasilitas"
                                        required
                                        value={form.data.owner_name}
                                        onChange={(event) =>
                                            form.setData(
                                                'owner_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormControl>
                                <FormControl error={form.errors.owner_email}>
                                    <Input
                                        id="owner_email"
                                        label="Email admin fasilitas"
                                        type="email"
                                        required
                                        value={form.data.owner_email}
                                        onChange={(event) =>
                                            form.setData(
                                                'owner_email',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormControl>
                            </FormGrid>
                            <p className="text-sm text-muted-foreground">
                                Tenant mendapat data awal produk dan logo Metta.
                                Akun admin fasilitas dibuat terpisah dari akun
                                dukungan Metta. Undangan email belum dikirim
                                otomatis.
                            </p>
                        </div>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}

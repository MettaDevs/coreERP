import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { ActionButton } from '@apperp/ui/action-button';
import { DatePicker } from '@apperp/ui/date-picker';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import {
    PageDialog as Dialog,
    PageDialogContent as DialogContent,
    PageDialogTrigger as DialogTrigger,
    PageDialogBody,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { useConfirmation } from '@apperp/ui/use-confirmation';
import { useForm } from '@inertiajs/react';
import { Cloud, Server } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { kindLabels, labelFor } from '@/lib/display';

type Hosting = 'provider' | 'client_server';

/**
 * Satu pilihan tempat berjalan, sebagai kartu yang dapat ditekan.
 *
 * Kartu, bukan daftar pilihan. Kedua jawabannya mengubah hampir seluruh langkah sesudahnya — siapa yang
 * menyiapkan database, ke mana pelanggan diarahkan, dan siapa yang memperbarui — dan kalimat penjelasnya
 * harus terbaca sebelum memilih, bukan tersembunyi di dalam daftar yang tertutup.
 */
function HostingOption({
    value,
    chosen,
    onChoose,
    icon,
    title,
    children,
}: {
    value: Hosting;
    chosen: boolean;
    onChoose: (value: Hosting) => void;
    icon: ReactNode;
    title: string;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-pressed={chosen}
            onClick={() => onChoose(value)}
            className={`flex w-full items-start gap-3 rounded-lg border p-3 text-start transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50 ${
                chosen
                    ? 'border-primary/30 bg-primary/10 ring-1 ring-ring'
                    : 'bg-background hover:bg-muted/60'
            }`}
        >
            <span
                className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md ${
                    chosen
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground'
                }`}
            >
                {icon}
            </span>
            <span className="min-w-0">
                <span className="block text-sm font-medium">{title}</span>
                <span className="mt-0.5 block text-xs text-muted-foreground">
                    {children}
                </span>
            </span>
        </button>
    );
}

/**
 * Formulirnya pendek dan berurutan, dan **jenis dipilih paling dulu**.
 *
 * Urutan itu bukan selera: jenis yang menentukan sisanya — apakah tanggal berakhir wajib, apakah
 * lingkungan ini boleh menghubungi dunia luar, apakah ia boleh berjalan di server klien, dan apakah ia
 * boleh lahir sama sekali. Formulir yang menanyakan nama lebih dulu memaksa operator mengisi hal yang
 * mungkin akan dibuangnya lagi.
 *
 * Tempat berjalan hanya ditanyakan untuk produksi. Demo dan sandbox selalu di server kita, dan server
 * menolak kombinasi lain; pilihannya dibuang sebelum dikirim bila jenisnya bukan produksi.
 *
 * Ringkasan di bawahnya menyebut juga yang **tidak** dipilih. Itu yang menggantikan dialog
 * konfirmasi tersendiri, dan itu tempat paling tepat untuk menyatakan akibat pelucutan sebelum
 * tombolnya ditekan — bukan sesudah.
 */
export default function CreateDialog({
    tenant,
    kinds,
}: {
    tenant: { id: string; name: string }[];
    kinds: string[];
}) {
    const { wrap: wrapLocalForm0Request, summary: localForm0RequestSummary } =
        useInertiaFeedback();

    const [open, setOpen] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const { confirm, confirmation } = useConfirmation();
    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
        transform,
        clearErrors,
        isDirty,
    } = useForm({
        kind: 'demo',
        tenant_id: tenant[0]?.id ?? '',
        name: '',
        expires_at: '',
        hosting: 'provider' as Hosting,
    });

    const production = data.kind === 'production';
    const demo = data.kind === 'demo';
    const clientServer = production && data.hosting === 'client_server';

    async function closeDraft() {
        if (processing) {
            return;
        }

        if (
            isDirty &&
            !(await confirm({
                title: 'Buang perubahan?',
                description: 'Isian yang belum disimpan akan dibuang.',
                confirmLabel: 'Buang perubahan',
                destructive: true,
            }))
        ) {
            return;
        }

        reset();
        clearErrors();
        setOpen(false);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        transform((values) => ({
            ...values,
            hosting: values.kind === 'production' ? values.hosting : 'provider',
        }));
        post(
            '/lingkungan',
            wrapLocalForm0Request({
                onSuccess: () => {
                    reset();
                    setOpen(false);
                },
            }),
        );
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setOpen(true);
                } else {
                    void closeDraft();
                }
            }}
        >
            {confirmation}
            <DialogTrigger asChild>
                <ActionButton action="create" disabled={tenant.length === 0} />
            </DialogTrigger>
            <DialogContent
                ref={contentRef}
                title="Lingkungan"
                recordTitle="Baru"
                busy={processing}
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
                            pending={processing}
                        />
                        <ActionButton
                            action="cancel"
                            disabled={processing}
                            onClick={() => void closeDraft()}
                        />
                    </PageDialogToolbar>
                    <PageDialogBody>
                        <>
                            {localForm0RequestSummary}
                            <FormErrorHeader errors={errors} />
                        </>

                        <div className="space-y-8 py-4">
                            <section className="space-y-4">
                                <h2 className="text-lg font-semibold">
                                    Identitas lingkungan
                                </h2>
                                <FormGrid>
                                    <FormControl error={errors.name}>
                                        <Input
                                            id="name"
                                            label="Nama lingkungan"
                                            required
                                            value={data.name}
                                            onChange={(e) =>
                                                setData('name', e.target.value)
                                            }
                                        />
                                    </FormControl>
                                    <FormControl error={errors.tenant_id}>
                                        <NativeSelect
                                            id="tenant"
                                            label="Tenant"
                                            required
                                            value={data.tenant_id}
                                            onChange={(e) =>
                                                setData(
                                                    'tenant_id',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {tenant.map((t) => (
                                                <option key={t.id} value={t.id}>
                                                    {t.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </FormControl>
                                    <FormControl error={errors.kind}>
                                        <NativeSelect
                                            id="kind"
                                            label="Jenis"
                                            required
                                            value={data.kind}
                                            onChange={(e) =>
                                                setData('kind', e.target.value)
                                            }
                                        >
                                            {kinds.map((k) => (
                                                <option key={k} value={k}>
                                                    {labelFor(kindLabels, k)}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </FormControl>
                                    {demo && (
                                        <FormControl error={errors.expires_at}>
                                            <DatePicker
                                                id="expires_at"
                                                label="Berakhir pada"
                                                required
                                                portalContainer={contentRef}
                                                value={data.expires_at}
                                                onValueChange={(value) =>
                                                    setData('expires_at', value)
                                                }
                                            />
                                        </FormControl>
                                    )}
                                </FormGrid>
                                {demo && (
                                    <p className="text-sm text-muted-foreground">
                                        Demo hanya berlaku sampai tanggal yang
                                        dipilih.
                                    </p>
                                )}
                            </section>
                            {production && (
                                <section className="space-y-4 border-t pt-6">
                                    <h2 className="text-lg font-semibold">
                                        Lokasi server
                                    </h2>
                                    <div
                                        role="group"
                                        aria-label="Lokasi server"
                                        className="grid gap-3 sm:grid-cols-2"
                                    >
                                        <HostingOption
                                            value="provider"
                                            chosen={data.hosting === 'provider'}
                                            onChoose={(value) =>
                                                setData('hosting', value)
                                            }
                                            icon={<Cloud className="size-4" />}
                                            title="Server kita"
                                        >
                                            Aplikasi dan database dikelola di
                                            server kita.
                                        </HostingOption>
                                        <HostingOption
                                            value="client_server"
                                            chosen={
                                                data.hosting === 'client_server'
                                            }
                                            onChoose={(value) =>
                                                setData('hosting', value)
                                            }
                                            icon={<Server className="size-4" />}
                                            title="Server klien"
                                        >
                                            Aplikasi dipasang di server klien
                                            dan dipantau dari konsol ini.
                                        </HostingOption>
                                    </div>
                                    {errors.hosting && (
                                        <p className="text-sm text-destructive">
                                            {errors.hosting}
                                        </p>
                                    )}
                                </section>
                            )}
                            <section className="space-y-3 rounded-md border bg-muted/30 p-4">
                                <h2 className="text-sm font-semibold">
                                    Setelah disimpan
                                </h2>
                                <p className="text-sm">
                                    {clientServer
                                        ? 'Buka rincian lingkungan, siapkan server klien, lalu jalankan perintah pemasangannya di sana.'
                                        : 'Buka rincian lingkungan dan pilih Siapkan. Lingkungan dapat digunakan setelah penyiapan selesai.'}
                                </p>
                                <dl className="grid gap-3 border-t pt-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Database
                                        </dt>
                                        <dd className="mt-1">
                                            {clientServer
                                                ? 'Dibuat saat pemasangan di server klien'
                                                : 'Disiapkan sendiri untuk lingkungan ini'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Email, webhook, dan pengiriman
                                            otomatis
                                        </dt>
                                        <dd className="mt-1">
                                            {production
                                                ? 'Diizinkan'
                                                : 'Dinonaktifkan agar data percobaan tidak terkirim keluar'}
                                        </dd>
                                    </div>
                                </dl>
                            </section>
                        </div>
                    </PageDialogBody>
                </form>
            </DialogContent>
        </Dialog>
    );
}

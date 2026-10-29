import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { ActionButton } from '@apperp/ui/action-button';
import { Checkbox } from '@apperp/ui/checkbox';
import { DatePicker } from '@apperp/ui/date-picker';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
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
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';

const formFields = [
    'legal_name',
    'admin_name',
    'admin_email',
    'app_ids',
    'first_environment',
    'first_environment_expires_at',
    'first_environment_hosting',
];

/**
 * Melahirkan satu tenant, beserta admin pertamanya.
 *
 * Dialog ini **tidak menulis apa pun ke database.** Ia mengirim isiannya ke Core, dan Core yang
 * membuat tenant, environment, membership, role Owner, entitlement, serta memasang module yang
 * dibeli. Alasannya ditulis di PRD: yang tahu cara menjalankan migration module, membaca registry
 * module, dan menyemai data awal hanyalah Core.
 *
 * Akibatnya bagi layar ini: hampir setiap penolakan datang dari aplikasi lain, lewat jaringan.
 * Yang bukan milik satu isian — kunci salah, alamat salah setel, Core mati — dirender sebagai
 * spanduk di atas formulir, karena kalimatnya panjang dan tempatnya memang bukan di bawah sebuah
 * kotak isian.
 */
export default function CreateDialog({
    app,
    onCreated,
}: {
    app: { id: string; name: string }[];
    onCreated?: () => void;
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
        clearErrors,
        isDirty,
    } = useForm({
        legal_name: '',
        admin_name: '',
        admin_email: '',
        app_ids: [] as string[],
        first_environment: 'demo',
        first_environment_expires_at: '',
        first_environment_hosting: 'provider',
    });

    // Galat yang bukan milik satu isian. Dikumpulkan dari sisa kunci, bukan dari satu nama yang
    // ditulis di dua tempat — begitu Core menambah sebab baru, ia langsung terlihat di sini
    // alih-alih hilang diam-diam karena tidak ada yang menambahkan namanya.
    const otherErrors = Object.entries(errors)
        .filter(
            ([key]) =>
                !formFields.some(
                    (field) => key === field || key.startsWith(`${field}.`),
                ),
        )
        .map(([, message]) => message)
        .filter((message): message is string => typeof message === 'string');

    function choose(id: string, chosen: boolean) {
        setData(
            'app_ids',
            chosen
                ? [...data.app_ids, id]
                : data.app_ids.filter((item) => item !== id),
        );
    }

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
        post(
            '/tenant',
            wrapLocalForm0Request({
                onSuccess: () => {
                    reset();
                    setOpen(false);
                    onCreated?.();
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
                <ActionButton action="create" />
            </DialogTrigger>
            <DialogContent
                ref={contentRef}
                title="Tenant"
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
                            {otherErrors.length > 0 && (
                                <div
                                    role="alert"
                                    className="rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive"
                                >
                                    {otherErrors.map((message) => (
                                        <p key={message}>{message}</p>
                                    ))}
                                </div>
                            )}
                            <section className="space-y-4">
                                <h2 className="text-lg font-semibold">
                                    Perusahaan dan admin
                                </h2>
                                <FormGrid>
                                    <FormControl
                                        error={errors.legal_name}
                                        hint="Gunakan nama perusahaan atau grup. Badan hukum di dalamnya dapat diatur setelah masuk ke ERP."
                                    >
                                        <Input
                                            id="legal_name"
                                            label="Nama tenant"
                                            required
                                            value={data.legal_name}
                                            onChange={(e) =>
                                                setData(
                                                    'legal_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </FormControl>
                                    <FormControl error={errors.admin_name}>
                                        <Input
                                            id="admin_name"
                                            label="Nama admin"
                                            required
                                            value={data.admin_name}
                                            onChange={(e) =>
                                                setData(
                                                    'admin_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </FormControl>
                                    <FormControl error={errors.admin_email}>
                                        <Input
                                            id="admin_email"
                                            label="Email admin"
                                            required
                                            type="email"
                                            value={data.admin_email}
                                            onChange={(e) =>
                                                setData(
                                                    'admin_email',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </FormControl>
                                </FormGrid>
                                <p className="text-sm text-muted-foreground">
                                    Email ini dipakai untuk masuk. Setelah
                                    disimpan, salin kata sandi sementara dan
                                    sampaikan kepada admin. Email undangan belum
                                    dikirim otomatis.
                                </p>
                            </section>
                            <section className="space-y-4 border-t pt-6">
                                <h2 className="text-lg font-semibold">
                                    Lingkungan pertama
                                </h2>
                                <FormGrid>
                                    <FormControl
                                        error={errors.first_environment}
                                    >
                                        <NativeSelect
                                            id="first_environment"
                                            label="Jenis lingkungan"
                                            value={data.first_environment}
                                            onChange={(e) =>
                                                setData(
                                                    'first_environment',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="demo">Demo</option>
                                            <option value="production">
                                                Produksi
                                            </option>
                                            <option value="none">
                                                Buat nanti
                                            </option>
                                        </NativeSelect>
                                    </FormControl>
                                    {data.first_environment ===
                                        'production' && (
                                        <FormControl
                                            error={
                                                errors.first_environment_hosting
                                            }
                                        >
                                            <NativeSelect
                                                id="first_environment_hosting"
                                                label="Lokasi server"
                                                value={
                                                    data.first_environment_hosting
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        'first_environment_hosting',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="provider">
                                                    Server kita
                                                </option>
                                                <option value="client_server">
                                                    Server klien
                                                </option>
                                            </NativeSelect>
                                        </FormControl>
                                    )}
                                    {data.first_environment === 'demo' && (
                                        <FormControl
                                            error={
                                                errors.first_environment_expires_at
                                            }
                                        >
                                            <DatePicker
                                                id="first_environment_expires_at"
                                                label="Berakhir pada"
                                                required
                                                portalContainer={contentRef}
                                                value={
                                                    data.first_environment_expires_at
                                                }
                                                onValueChange={(value) =>
                                                    setData(
                                                        'first_environment_expires_at',
                                                        value,
                                                    )
                                                }
                                            />
                                        </FormControl>
                                    )}
                                </FormGrid>
                                <p className="text-sm text-muted-foreground">
                                    {data.first_environment === 'none'
                                        ? 'Tenant dan akun admin dibuat sekarang. Lingkungan dapat ditambahkan melalui menu Lingkungan.'
                                        : data.first_environment === 'demo'
                                          ? 'Demo memakai database sendiri dan hanya berlaku sampai tanggal yang dipilih.'
                                          : data.first_environment_hosting ===
                                              'client_server'
                                            ? 'Setelah tersimpan, buka lingkungan untuk menyiapkan pemasangan di server klien.'
                                            : 'Lingkungan produksi disiapkan di server kita untuk kegiatan operasional.'}
                                </p>
                            </section>
                            <section className="space-y-4 border-t pt-6">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-lg font-semibold">
                                        Aplikasi yang diberikan
                                    </h2>
                                    <span className="text-sm text-muted-foreground">
                                        {data.app_ids.length} dipilih
                                    </span>
                                </div>
                                {app.length === 0 ? (
                                    <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                        Katalog aplikasi masih kosong. Daftarkan
                                        aplikasi di Core agar dapat dipilih di
                                        sini.
                                    </p>
                                ) : (
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {app.map((item) => (
                                            <div
                                                key={item.id}
                                                className="flex items-start gap-3 rounded-md border p-3"
                                            >
                                                <Checkbox
                                                    id={`app-${item.id}`}
                                                    checked={data.app_ids.includes(
                                                        item.id,
                                                    )}
                                                    onCheckedChange={(value) =>
                                                        choose(
                                                            item.id,
                                                            value === true,
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`app-${item.id}`}
                                                    className="min-w-0 flex-1 font-normal"
                                                >
                                                    <span className="block">
                                                        {item.name}
                                                    </span>
                                                    <span className="mt-1 block font-mono text-xs break-all text-muted-foreground">
                                                        {item.id}
                                                    </span>
                                                </Label>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <p className="text-sm text-muted-foreground">
                                    Aplikasi pendukung yang diperlukan akan
                                    disertakan otomatis.
                                </p>
                                {errors.app_ids && (
                                    <p className="text-sm text-destructive">
                                        {errors.app_ids}
                                    </p>
                                )}
                            </section>
                        </div>
                    </PageDialogBody>
                </form>
            </DialogContent>
        </Dialog>
    );
}

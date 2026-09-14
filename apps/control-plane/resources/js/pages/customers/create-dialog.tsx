import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { NativeSelect } from '@apperp/ui/native-select';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

const formFields = [
    'legal_name',
    'admin_name',
    'admin_email',
    'app_ids',
    'first_environment',
    'first_environment_expires_at',
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
}: {
    app: { id: string; name: string }[];
}) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            legal_name: '',
            admin_name: '',
            admin_email: '',
            app_ids: [] as string[],
            first_environment: 'demo',
            first_environment_expires_at: '',
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

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/tenant', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(opened) => {
                setOpen(opened);

                // Menutup dialog membuang galatnya. Kalau tidak, penolakan Core dari percobaan
                // sebelumnya menyambut operator saat ia membukanya lagi — seolah percobaan yang
                // belum dimulai sudah gagal.
                if (!opened) {
                    clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>Tenant baru</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Tenant baru</DialogTitle>
                        <DialogDescription>
                            Core yang membuatnya. Konsol ini hanya memerintah,
                            lalu menampilkan kata sandi sementaranya satu kali.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {otherErrors.length > 0 && (
                            <div className="rounded-md border border-destructive/40 bg-red-50 px-4 py-3 text-sm text-destructive dark:bg-red-950/40 dark:text-red-200">
                                {otherErrors.map((message) => (
                                    <p key={message}>{message}</p>
                                ))}
                            </div>
                        )}

                        <div className="space-y-2">
                            <Input
                                id="legal_name"
                                label="Nama tenant"
                                required
                                value={data.legal_name}
                                onChange={(e) =>
                                    setData('legal_name', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Nama perusahaan atau grupnya, bukan nama badan
                                hukum tertentu. Satu tenant boleh memuat
                                beberapa badan hukum sekaligus, dan
                                masing-masing didaftarkan di dalam ERP-nya
                                lengkap dengan kode perusahaan dan negaranya.
                            </p>
                            {errors.legal_name && (
                                <p className="text-sm text-destructive">
                                    {errors.legal_name}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <NativeSelect
                                id="first_environment"
                                label="Lingkungan pertama"
                                value={data.first_environment}
                                onChange={(e) =>
                                    setData('first_environment', e.target.value)
                                }
                            >
                                <option value="demo">
                                    Demo — berbatas waktu, database sendiri
                                </option>
                                <option value="production">
                                    Produksi — tempat kerja sebenarnya
                                </option>
                                <option value="none">
                                    Belum — ditambahkan nanti
                                </option>
                            </NativeSelect>
                            <p className="text-xs text-muted-foreground">
                                Calon tenant yang belum tentu jadi membeli tidak
                                perlu diberi produksi. Produksi yang terlanjur
                                lahir adalah tempat kerja kosong yang tidak
                                pernah dipakai siapa pun, sekaligus alamat yang
                                sudah terpakai.
                            </p>
                            {errors.first_environment && (
                                <p className="text-sm text-destructive">
                                    {errors.first_environment}
                                </p>
                            )}
                        </div>

                        {data.first_environment === 'demo' && (
                            <div className="space-y-2">
                                <Input
                                    id="first_environment_expires_at"
                                    label="Berakhir pada"
                                    required
                                    type="date"
                                    value={data.first_environment_expires_at}
                                    onChange={(e) =>
                                        setData(
                                            'first_environment_expires_at',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Demo wajib punya tanggal berakhir. Tanpa itu
                                    ia tinggal selamanya, dan tidak ada yang
                                    menyadarinya sampai disknya penuh.
                                </p>
                                {errors.first_environment_expires_at && (
                                    <p className="text-sm text-destructive">
                                        {errors.first_environment_expires_at}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="space-y-2">
                            <Input
                                id="admin_name"
                                label="Nama admin"
                                required
                                value={data.admin_name}
                                onChange={(e) =>
                                    setData('admin_name', e.target.value)
                                }
                            />
                            {errors.admin_name && (
                                <p className="text-sm text-destructive">
                                    {errors.admin_name}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Input
                                id="admin_email"
                                label="Email admin"
                                required
                                type="email"
                                value={data.admin_email}
                                onChange={(e) =>
                                    setData('admin_email', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Tidak ada surat yang dikirim ke alamat ini —
                                repo ini belum punya jalur email sama sekali. Ia
                                dipakai untuk masuk, dan kata sandinya
                                disampaikan operator.
                            </p>
                            {errors.admin_email && (
                                <p className="text-sm text-destructive">
                                    {errors.admin_email}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label>App yang dibeli</Label>
                            {app.length === 0 ? (
                                <p className="rounded-md border border-dashed px-4 py-3 text-sm text-muted-foreground">
                                    Katalog app kosong. Daftarkan manifest app
                                    di Core lebih dulu — tanpa satu pun app,
                                    tenant lahir ke peluncur yang kosong.
                                </p>
                            ) : (
                                <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border p-3">
                                    {app.map((item) => (
                                        <div
                                            key={item.id}
                                            className="flex items-center gap-2"
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
                                                className="font-normal"
                                            >
                                                {item.name}
                                                <span className="ms-2 font-mono text-xs text-muted-foreground">
                                                    {item.id}
                                                </span>
                                            </Label>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <p className="text-xs text-muted-foreground">
                                Prerequisite ditambahkan Core sendiri, jadi
                                memilih satu app sudah cukup untuk membawa serta
                                yang dibutuhkannya.
                            </p>
                            {errors.app_ids && (
                                <p className="text-sm text-destructive">
                                    {errors.app_ids}
                                </p>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Meminta Core…' : 'Buat tenant'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

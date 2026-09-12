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
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

const formFields = ['legal_name', 'admin_name', 'admin_email', 'app_ids'];

/**
 * Melahirkan satu pelanggan, beserta admin pertamanya.
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
        post('/pelanggan', {
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
                <Button>Pelanggan baru</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Pelanggan baru</DialogTitle>
                        <DialogDescription>
                            Core yang membuatnya. Konsol ini hanya memerintah,
                            lalu menampilkan kata sandi sementaranya satu kali.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {otherErrors.length > 0 && (
                            <div className="border-destructive/40 text-destructive rounded-md border bg-red-50 px-4 py-3 text-sm dark:bg-red-950/40 dark:text-red-200">
                                {otherErrors.map((message) => (
                                    <p key={message}>{message}</p>
                                ))}
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="legal_name">Nama badan hukum</Label>
                            <Input
                                id="legal_name"
                                value={data.legal_name}
                                onChange={(e) =>
                                    setData('legal_name', e.target.value)
                                }
                                placeholder="PT Sumber Sehat Nusantara"
                            />
                            <p className="text-muted-foreground text-xs">
                                Nama resmi seperti tertulis di aktanya, bukan
                                nama panggilan. Ia yang muncul di dokumen yang
                                dicetak pelanggan.
                            </p>
                            {errors.legal_name && (
                                <p className="text-destructive text-sm">
                                    {errors.legal_name}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="admin_name">Nama admin</Label>
                            <Input
                                id="admin_name"
                                value={data.admin_name}
                                onChange={(e) =>
                                    setData('admin_name', e.target.value)
                                }
                                placeholder="Siti Rahmawati"
                            />
                            {errors.admin_name && (
                                <p className="text-destructive text-sm">
                                    {errors.admin_name}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="admin_email">Email admin</Label>
                            <Input
                                id="admin_email"
                                type="email"
                                value={data.admin_email}
                                onChange={(e) =>
                                    setData('admin_email', e.target.value)
                                }
                                placeholder="siti@sumbersehat.co.id"
                            />
                            <p className="text-muted-foreground text-xs">
                                Tidak ada surat yang dikirim ke alamat ini —
                                repo ini belum punya jalur email sama sekali. Ia
                                dipakai untuk masuk, dan kata sandinya
                                disampaikan operator.
                            </p>
                            {errors.admin_email && (
                                <p className="text-destructive text-sm">
                                    {errors.admin_email}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label>App yang dibeli</Label>
                            {app.length === 0 ? (
                                <p className="text-muted-foreground rounded-md border border-dashed px-4 py-3 text-sm">
                                    Katalog app kosong. Daftarkan manifest app
                                    di Core lebih dulu — tanpa satu pun app,
                                    pelanggan lahir ke peluncur yang kosong.
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
                                                <span className="text-muted-foreground ms-2 font-mono text-xs">
                                                    {item.id}
                                                </span>
                                            </Label>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <p className="text-muted-foreground text-xs">
                                Prerequisite ditambahkan Core sendiri, jadi
                                memilih satu app sudah cukup untuk membawa serta
                                yang dibutuhkannya.
                            </p>
                            {errors.app_ids && (
                                <p className="text-destructive text-sm">
                                    {errors.app_ids}
                                </p>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Meminta Core…' : 'Buat pelanggan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

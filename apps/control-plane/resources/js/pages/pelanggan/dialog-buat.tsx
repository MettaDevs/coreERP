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

const isianFormulir = [
    'nama_badan_hukum',
    'nama_admin',
    'email_admin',
    'app_ids',
];

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
export default function DialogBuat({
    app,
}: {
    app: { id: string; nama: string }[];
}) {
    const [buka, setBuka] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            nama_badan_hukum: '',
            nama_admin: '',
            email_admin: '',
            app_ids: [] as string[],
        });

    // Galat yang bukan milik satu isian. Dikumpulkan dari sisa kunci, bukan dari satu nama yang
    // ditulis di dua tempat — begitu Core menambah sebab baru, ia langsung terlihat di sini
    // alih-alih hilang diam-diam karena tidak ada yang menambahkan namanya.
    const galatLain = Object.entries(errors)
        .filter(
            ([kunci]) =>
                !isianFormulir.some(
                    (isian) => kunci === isian || kunci.startsWith(`${isian}.`),
                ),
        )
        .map(([, pesan]) => pesan)
        .filter((pesan): pesan is string => typeof pesan === 'string');

    function pilih(id: string, dipilih: boolean) {
        setData(
            'app_ids',
            dipilih
                ? [...data.app_ids, id]
                : data.app_ids.filter((satu) => satu !== id),
        );
    }

    function kirim(e: FormEvent) {
        e.preventDefault();
        post('/pelanggan', {
            onSuccess: () => {
                reset();
                setBuka(false);
            },
        });
    }

    return (
        <Dialog
            open={buka}
            onOpenChange={(terbuka) => {
                setBuka(terbuka);

                // Menutup dialog membuang galatnya. Kalau tidak, penolakan Core dari percobaan
                // sebelumnya menyambut operator saat ia membukanya lagi — seolah percobaan yang
                // belum dimulai sudah gagal.
                if (!terbuka) {
                    clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>Pelanggan baru</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={kirim}>
                    <DialogHeader>
                        <DialogTitle>Pelanggan baru</DialogTitle>
                        <DialogDescription>
                            Core yang membuatnya. Konsol ini hanya memerintah,
                            lalu menampilkan kata sandi sementaranya satu kali.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {galatLain.length > 0 && (
                            <div className="border-destructive/40 text-destructive rounded-md border bg-red-50 px-4 py-3 text-sm">
                                {galatLain.map((pesan) => (
                                    <p key={pesan}>{pesan}</p>
                                ))}
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="nama_badan_hukum">
                                Nama badan hukum
                            </Label>
                            <Input
                                id="nama_badan_hukum"
                                value={data.nama_badan_hukum}
                                onChange={(e) =>
                                    setData('nama_badan_hukum', e.target.value)
                                }
                                placeholder="PT Sumber Sehat Nusantara"
                            />
                            <p className="text-muted-foreground text-xs">
                                Nama resmi seperti tertulis di aktanya, bukan
                                nama panggilan. Ia yang muncul di dokumen yang
                                dicetak pelanggan.
                            </p>
                            {errors.nama_badan_hukum && (
                                <p className="text-destructive text-sm">
                                    {errors.nama_badan_hukum}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="nama_admin">Nama admin</Label>
                            <Input
                                id="nama_admin"
                                value={data.nama_admin}
                                onChange={(e) =>
                                    setData('nama_admin', e.target.value)
                                }
                                placeholder="Siti Rahmawati"
                            />
                            {errors.nama_admin && (
                                <p className="text-destructive text-sm">
                                    {errors.nama_admin}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="email_admin">Email admin</Label>
                            <Input
                                id="email_admin"
                                type="email"
                                value={data.email_admin}
                                onChange={(e) =>
                                    setData('email_admin', e.target.value)
                                }
                                placeholder="siti@sumbersehat.co.id"
                            />
                            <p className="text-muted-foreground text-xs">
                                Tidak ada surat yang dikirim ke alamat ini —
                                repo ini belum punya jalur email sama sekali. Ia
                                dipakai untuk masuk, dan kata sandinya
                                disampaikan operator.
                            </p>
                            {errors.email_admin && (
                                <p className="text-destructive text-sm">
                                    {errors.email_admin}
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
                                    {app.map((satu) => (
                                        <div
                                            key={satu.id}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                id={`app-${satu.id}`}
                                                checked={data.app_ids.includes(
                                                    satu.id,
                                                )}
                                                onCheckedChange={(nilai) =>
                                                    pilih(
                                                        satu.id,
                                                        nilai === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor={`app-${satu.id}`}
                                                className="font-normal"
                                            >
                                                {satu.nama}
                                                <span className="text-muted-foreground ms-2 font-mono text-xs">
                                                    {satu.id}
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

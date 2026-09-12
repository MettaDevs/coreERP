import { Button } from '@apperp/ui/button';
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
import { namaJenis, sebut } from '@/lib/tampilan';

/**
 * Formulirnya pendek dan berurutan, dan **jenis dipilih paling dulu**.
 *
 * Urutan itu bukan selera: jenis yang menentukan sisanya — apakah tanggal berakhir wajib, apakah
 * lingkungan ini boleh menghubungi dunia luar, dan apakah ia boleh lahir sama sekali. Formulir yang
 * menanyakan nama lebih dulu memaksa operator mengisi hal yang mungkin akan dibuangnya lagi.
 *
 * Ringkasan di bawahnya menyebut juga yang **tidak** dipilih. Itu yang menggantikan dialog
 * konfirmasi tersendiri, dan itu tempat paling tepat untuk menyatakan akibat pelucutan sebelum
 * tombolnya ditekan — bukan sesudah.
 */
export default function DialogBuat({
    tenant,
    jenis,
}: {
    tenant: { id: string; nama: string }[];
    jenis: string[];
}) {
    const [buka, setBuka] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        jenis: 'demo',
        tenant_id: tenant[0]?.id ?? '',
        nama: '',
        berakhir: '',
    });

    const produksi = data.jenis === 'production';
    const demo = data.jenis === 'demo';

    function kirim(e: FormEvent) {
        e.preventDefault();
        post('/lingkungan', {
            onSuccess: () => {
                reset();
                setBuka(false);
            },
        });
    }

    return (
        <Dialog open={buka} onOpenChange={setBuka}>
            <DialogTrigger asChild>
                <Button disabled={tenant.length === 0}>Buat lingkungan</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={kirim}>
                    <DialogHeader>
                        <DialogTitle>Buat lingkungan</DialogTitle>
                        <DialogDescription>
                            Jenis menentukan sisanya, jadi ia dipilih lebih
                            dulu.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="jenis">Jenis</Label>
                            <NativeSelect
                                id="jenis"
                                value={data.jenis}
                                onChange={(e) =>
                                    setData('jenis', e.target.value)
                                }
                            >
                                {jenis.map((j) => (
                                    <option key={j} value={j}>
                                        {sebut(namaJenis, j)}
                                    </option>
                                ))}
                            </NativeSelect>
                            {errors.jenis && (
                                <p className="text-destructive text-sm">
                                    {errors.jenis}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="tenant">Pelanggan</Label>
                            <NativeSelect
                                id="tenant"
                                value={data.tenant_id}
                                onChange={(e) =>
                                    setData('tenant_id', e.target.value)
                                }
                            >
                                {tenant.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.nama}
                                    </option>
                                ))}
                            </NativeSelect>
                            {errors.tenant_id && (
                                <p className="text-destructive text-sm">
                                    {errors.tenant_id}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="nama">Nama</Label>
                            <Input
                                id="nama"
                                value={data.nama}
                                onChange={(e) =>
                                    setData('nama', e.target.value)
                                }
                                placeholder="Uji coba penagihan"
                            />
                            {errors.nama && (
                                <p className="text-destructive text-sm">
                                    {errors.nama}
                                </p>
                            )}
                        </div>

                        {demo && (
                            <div className="space-y-2">
                                <Label htmlFor="berakhir">Berakhir pada</Label>
                                <Input
                                    id="berakhir"
                                    type="date"
                                    value={data.berakhir}
                                    onChange={(e) =>
                                        setData('berakhir', e.target.value)
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    Demo wajib punya tanggal berakhir. Tanpa itu
                                    ia tinggal selamanya, dan tidak ada yang
                                    menyadarinya sampai disknya penuh.
                                </p>
                                {errors.berakhir && (
                                    <p className="text-destructive text-sm">
                                        {errors.berakhir}
                                    </p>
                                )}
                            </div>
                        )}

                        <dl className="bg-muted/40 space-y-1.5 rounded-md border p-4 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Kirim keluar
                                </dt>
                                <dd
                                    className={
                                        produksi
                                            ? 'font-medium'
                                            : 'font-medium text-amber-700'
                                    }
                                >
                                    {produksi ? 'Ya' : 'Tidak'}
                                </dd>
                            </div>
                            {/*
                                Dua baris ini sempat berbunyi "Database sendiri: Belum" dan "Dapat
                                dimasuki: Belum", dan itu menyesatkan: keduanya berdiri sejajar
                                dengan "Kirim keluar", yang memang sebuah pilihan. "Belum" karena
                                itu terbaca seperti kotak yang lupa dicentang, padahal ia langkah
                                berikutnya yang pasti terjadi. Yang dibetulkan bukan datanya
                                melainkan kata kerjanya — ini rencana, bukan setelan.
                            */}
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Database
                                </dt>
                                <dd className="font-medium">
                                    Dibuatkan sendiri, di langkah berikutnya
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Setelah dibuat
                                </dt>
                                <dd className="font-medium">
                                    Belum dapat dimasuki
                                </dd>
                            </div>
                            <p className="text-muted-foreground pt-2 text-xs">
                                {produksi
                                    ? 'Produksi boleh menghubungi dunia luar: email, webhook, dan pengiriman otomatis berjalan seperti biasa.'
                                    : 'Di luar produksi, webhook dan pengiriman otomatis dimatikan. Itu satu-satunya alasan lingkungan terpisah ada — supaya salinan tidak menghubungi pelanggan sungguhan.'}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                Yang tercatat di sini baru registry-nya.
                                Databasenya disiapkan satu perintah sesudahnya,
                                dan perintahnya muncul di halaman rincian —
                                sampai itu selesai, lingkungannya tidak dapat
                                dibuka siapa pun.
                            </p>
                        </dl>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            Buat
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

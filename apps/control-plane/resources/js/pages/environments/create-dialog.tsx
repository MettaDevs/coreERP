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
import { NativeSelect } from '@apperp/ui/native-select';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { kindLabels, labelFor } from '@/lib/display';

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
export default function CreateDialog({
    tenant,
    kinds,
}: {
    tenant: { id: string; name: string }[];
    kinds: string[];
}) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        kind: 'demo',
        tenant_id: tenant[0]?.id ?? '',
        name: '',
        expires_at: '',
    });

    const production = data.kind === 'production';
    const demo = data.kind === 'demo';

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/lingkungan', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button disabled={tenant.length === 0}>Buat lingkungan</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Buat lingkungan</DialogTitle>
                        <DialogDescription>
                            Jenis menentukan sisanya, jadi ia dipilih lebih
                            dulu.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <NativeSelect
                                id="kind"
                                label="Jenis"
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
                            {errors.kind && (
                                <p className="text-sm text-destructive">
                                    {errors.kind}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <NativeSelect
                                id="tenant"
                                label="Tenant"
                                value={data.tenant_id}
                                onChange={(e) =>
                                    setData('tenant_id', e.target.value)
                                }
                            >
                                {tenant.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            {errors.tenant_id && (
                                <p className="text-sm text-destructive">
                                    {errors.tenant_id}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Input
                                id="name"
                                label="Nama"
                                required
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                            />
                            {errors.name && (
                                <p className="text-sm text-destructive">
                                    {errors.name}
                                </p>
                            )}
                        </div>

                        {demo && (
                            <div className="space-y-2">
                                <Input
                                    id="expires_at"
                                    label="Berakhir pada"
                                    required
                                    type="date"
                                    value={data.expires_at}
                                    onChange={(e) =>
                                        setData('expires_at', e.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Demo wajib punya tanggal berakhir. Tanpa itu
                                    ia tinggal selamanya, dan tidak ada yang
                                    menyadarinya sampai disknya penuh.
                                </p>
                                {errors.expires_at && (
                                    <p className="text-sm text-destructive">
                                        {errors.expires_at}
                                    </p>
                                )}
                            </div>
                        )}

                        <dl className="space-y-1.5 rounded-md border bg-muted/40 p-4 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Kirim keluar
                                </dt>
                                <dd
                                    className={
                                        production
                                            ? 'font-medium'
                                            : 'font-medium text-amber-700'
                                    }
                                >
                                    {production ? 'Ya' : 'Tidak'}
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
                            <p className="pt-2 text-xs text-muted-foreground">
                                {production
                                    ? 'Produksi boleh menghubungi dunia luar: email, webhook, dan pengiriman otomatis berjalan seperti biasa.'
                                    : 'Di luar produksi, webhook dan pengiriman otomatis dimatikan. Itu satu-satunya alasan lingkungan terpisah ada — supaya salinan tidak menghubungi orang sungguhan.'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Yang tercatat di sini baru registry-nya.
                                Databasenya disiapkan satu langkah sesudahnya,
                                lewat tombol di halaman rincian — sampai itu
                                selesai, lingkungannya tidak dapat dibuka siapa
                                pun.
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

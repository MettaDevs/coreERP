import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { PostingCheck } from '@/components/finance/posting-check';
import type {
    PostingCheckLine,
    PostingCheckProblem,
} from '@/components/finance/posting-check';
import { Button } from '@apperp/ui/button';
import { Field, FieldDescription, FieldGroup } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { api, errorMessage } from '../../api';
import type { Context } from './penerimaan';
import { tanggalTampil } from './penerimaan';

/** Hasil pratinjau atau proses "Post penyusutan" satu entitas legal, buku, dan tanggal akhir periode. */
type HasilPost = {
    assets: number;
    register_total: string;
    skipped: {
        proposed: number;
        posted: number;
        reversed: number;
        other_book: number;
    };
    blockers: { field: string; message: string }[];
    posting: {
        posting_id: string;
        status: string;
        posting_date: string | null;
        currency: { code: string; decimals: number } | null;
        total: string | null;
        lines: PostingCheckLine[];
        problems: PostingCheckProblem[];
    } | null;
};

export type BukuPost = { id: string; kode: string };

const uang = (nilai: string, desimal = 2) =>
    Number(nilai).toLocaleString('id-ID', {
        minimumFractionDigits: desimal,
        maximumFractionDigits: desimal,
    });

/** Kalimat keadaan jurnal yang akan terbit, dalam bahasa yang dipakai pengguna. */
function keadaan(status: string): string {
    switch (status) {
        case 'pending':
            return 'Jurnal ini siap dikirim ke aplikasi finance begitu penyusutannya di-post.';
        case 'held':
            return 'Jurnal ini akan tertahan sampai masalah di bawah dibenahi. Penyusutannya tetap dapat di-post; setelah dibenahi, owner atau admin menekan Validasi ulang di layar Posting finance.';
        case 'manual':
            return 'Jurnal dicatat tetapi tidak dikirim: pengiriman posting entitas legal ini belum aktif, atau tanggalnya sebelum cutover.';
        default:
            return '';
    }
}

/** Periode yang tidak ikut proses ini, beserta alasannya. */
function tidakIkut(skipped: HasilPost['skipped']): string[] {
    return [
        skipped.proposed > 0 &&
            `${skipped.proposed} periode masih usulan dan belum ikut; finalkan dulu.`,
        skipped.posted > 0 &&
            `${skipped.posted} periode sudah di-post sebelumnya.`,
        skipped.reversed > 0 &&
            `${skipped.reversed} periode sudah dibalik, jadi bebannya tidak dikirim.`,
        skipped.other_book > 0 &&
            `${skipped.other_book} periode tidak ikut karena jurnal asetnya dikirim lewat buku lain.`,
    ].filter((baris): baris is string => Boolean(baris));
}

/**
 * "Post penyusutan" (feed posting finance, TODO 11.2.7): satu jurnal ringkas untuk penyusutan final
 * satu entitas legal, buku, dan tanggal akhir periode.
 *
 * Pratinjaunya memakai komponen pemeriksaan posting yang sama dengan layar pantau posting Core (K-22):
 * yang terlihat di sini persis yang akan terbit, beserta jumlah aset yang ikut dan total register yang
 * harus sama dengan total jurnalnya.
 */
export function DepreciationPostingSheet({
    context,
    books,
    defaultPeriodEnd,
    onClose,
    onPosted,
}: {
    context: Context;
    books: BukuPost[];
    defaultPeriodEnd: string;
    onClose: () => void;
    onPosted: () => void;
}) {
    const [bukuId, setBukuId] = useState(books[0]?.id ?? '');
    const [akhir, setAkhir] = useState(defaultPeriodEnd);
    const [hasil, setHasil] = useState<HasilPost | null>(null);
    const [galat, setGalat] = useState('');
    const [sibuk, setSibuk] = useState(false);

    const masukan = () => ({
        legal_entity_id: context.legal_entity_id ?? '',
        buku_id: bukuId,
        period_ends_on: akhir,
    });

    const periksa = async () => {
        setSibuk(true);
        setGalat('');

        try {
            const jawab = await api<{ data: HasilPost }>(
                `/penyusutan/posting/pratinjau?${new URLSearchParams(masukan()).toString()}`,
            );
            setHasil(jawab.data);
        } catch (caught) {
            setGalat(
                errorMessage(caught, 'Pratinjau jurnal belum dapat disusun.'),
            );
        } finally {
            setSibuk(false);
        }
    };

    const post = async () => {
        setSibuk(true);
        setGalat('');

        try {
            const jawab = await api<{ data: HasilPost }>(
                '/penyusutan/posting',
                {
                    method: 'POST',
                    body: JSON.stringify(masukan()),
                },
            );
            toast.success(
                jawab.data.posting
                    ? `Penyusutan ${jawab.data.assets} aset di-post.`
                    : 'Tidak ada penyusutan final yang perlu di-post.',
            );
            onPosted();
            onClose();
        } catch (caught) {
            setGalat(errorMessage(caught, 'Penyusutan belum dapat di-post.'));
        } finally {
            setSibuk(false);
        }
    };

    const ganti = (update: () => void) => {
        update();
        setHasil(null);
    };

    const siapPost =
        hasil !== null && hasil.blockers.length === 0 && hasil.posting !== null;
    const desimal = hasil?.posting?.currency?.decimals ?? 2;

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                side="right"
                className="w-full gap-0 p-0 sm:max-w-3xl"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>Post penyusutan</SheetTitle>
                    <SheetDescription>
                        Mengirim penyusutan final satu buku dan satu periode ke
                        aplikasi finance sebagai satu jurnal ringkas, bertanggal
                        akhir periode.
                    </SheetDescription>
                </SheetHeader>
                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
                    {!context.legal_entity_id ? (
                        <p className="text-destructive text-sm">
                            Pilih entitas legal aktif terlebih dahulu pada
                            header CoreERP.
                        </p>
                    ) : (
                        <FieldGroup>
                            <Field>
                                <NativeSelect
                                    label="Buku penyusutan"
                                    required
                                    value={bukuId}
                                    onChange={(event) =>
                                        ganti(() =>
                                            setBukuId(event.target.value),
                                        )
                                    }
                                >
                                    {books.map((buku) => (
                                        <NativeSelectOption
                                            key={buku.id}
                                            value={buku.id}
                                        >
                                            {buku.kode}
                                        </NativeSelectOption>
                                    ))}
                                </NativeSelect>
                                <FieldDescription>
                                    Hanya buku yang mengirim jurnal perolehan
                                    asetnya yang mengirim penyusutan; buku lain
                                    tetap tercatat di register.
                                </FieldDescription>
                            </Field>
                            <Field>
                                <Input
                                    label="Akhir periode"
                                    type="date"
                                    required
                                    value={akhir}
                                    onChange={(event) =>
                                        ganti(() =>
                                            setAkhir(event.target.value),
                                        )
                                    }
                                />
                            </Field>
                        </FieldGroup>
                    )}

                    {galat && (
                        <p className="text-destructive text-sm">{galat}</p>
                    )}

                    {hasil && (
                        <div className="space-y-3">
                            <p className="text-sm font-medium">
                                {hasil.assets} aset ikut · total register{' '}
                                {hasil.posting?.currency?.code ?? 'IDR'}{' '}
                                {uang(hasil.register_total, desimal)}
                            </p>
                            {tidakIkut(hasil.skipped).map((baris) => (
                                <p
                                    key={baris}
                                    className="text-muted-foreground text-sm"
                                >
                                    {baris}
                                </p>
                            ))}
                            {hasil.blockers.length > 0 && (
                                <div className="border-destructive/40 bg-destructive/5 space-y-2 rounded-md border px-4 py-3">
                                    <p className="flex items-center gap-2 text-sm font-medium">
                                        <TriangleAlert className="size-4" />
                                        Belum dapat di-post
                                    </p>
                                    {hasil.blockers.map((blocker) => (
                                        <p
                                            key={blocker.field}
                                            className="text-sm"
                                        >
                                            {blocker.message}
                                        </p>
                                    ))}
                                </div>
                            )}
                            {hasil.posting ? (
                                <>
                                    <p className="text-sm">
                                        {keadaan(hasil.posting.status)} Tanggal
                                        jurnal{' '}
                                        {tanggalTampil(
                                            hasil.posting.posting_date,
                                        )}
                                        .
                                    </p>
                                    <PostingCheck
                                        lines={hasil.posting.lines}
                                        problems={hasil.posting.problems}
                                        currencyCode={
                                            hasil.posting.currency?.code ??
                                            'IDR'
                                        }
                                        currencyDecimals={desimal}
                                    />
                                </>
                            ) : (
                                hasil.blockers.length === 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        Tidak ada penyusutan final yang perlu
                                        di-post untuk buku dan periode ini.
                                    </p>
                                )
                            )}
                        </div>
                    )}
                </div>
                <SheetFooter className="border-t px-6 py-4">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={
                            sibuk ||
                            !context.legal_entity_id ||
                            !bukuId ||
                            !akhir
                        }
                        onClick={() => void periksa()}
                    >
                        Periksa
                    </Button>
                    <Button
                        type="button"
                        disabled={sibuk || !siapPost}
                        onClick={() => void post()}
                    >
                        {sibuk ? 'Memproses…' : 'Post'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { api, errorMessage } from '../../api';
import { DepreciationPostingSheet } from './DepreciationPostingSheet';
import type { BukuPost } from './DepreciationPostingSheet';
import type { Context } from './penerimaan';

type Book = {
    id: string;
    aset_code: string;
    book_code: string;
    profile_name: string;
    method: string;
    frequency: string;
    net_book_value: string;
    currency_code: string;
};
type Period = {
    id: string;
    aset_code: string;
    book_code: string;
    period_starts_on: string;
    period_ends_on: string;
    amount: string;
    status: string;
    currency_code: string;
    reverses_period_id: string | null;
    buku_id: string | null;
    /** Lapisan posting bukunya; `none` berarti buku itu tidak pernah mengirim ke aplikasi finance. */
    posting_layer: string | null;
    /** Posting finance yang membawa periode ini; kosong berarti belum di-post. */
    posted_posting_id: string | null;
};

/**
 * Buku yang dapat di-post dari periode yang tampil, dan tanggal akhir periode final terbaru yang
 * belum di-post sebagai isian awal "Post penyusutan". Buku berlapisan `none` dilewati: menawarkannya
 * hanya berujung pada penolakan, dan periodenya yang tidak pernah di-post akan menyesatkan tanggal awal.
 */
function pilihanPost(periods: Period[]): { books: BukuPost[]; akhir: string } {
    const books = new Map<string, BukuPost>();
    let akhir = '';

    for (const period of periods) {
        if (!period.buku_id || period.posting_layer === 'none') {
            continue;
        }

        if (!books.has(period.buku_id)) {
            books.set(period.buku_id, {
                id: period.buku_id,
                kode: period.book_code,
            });
        }

        if (
            period.status === 'final' &&
            !period.reverses_period_id &&
            !period.posted_posting_id &&
            period.period_ends_on > akhir
        ) {
            akhir = period.period_ends_on;
        }
    }

    return {
        books: [...books.values()].sort((a, b) => a.kode.localeCompare(b.kode)),
        akhir,
    };
}

export default function DepreciationPage({
    canCreate,
    canFinalize,
    canCorrect,
    canPost,
    context,
}: {
    canCreate: boolean;
    canFinalize: boolean;
    canCorrect: boolean;
    canPost: boolean;
    context: Context;
}) {
    const [books, setBooks] = useState<Book[]>([]);
    const [periods, setPeriods] = useState<Period[]>([]);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState<Book | null>(null);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [bulkResult, setBulkResult] = useState('');
    const [postOpen, setPostOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    // Effect adalah satu-satunya pemilik pengambilan data. Pemuatan ulang setelah
    // proposal, finalisasi, atau koreksi dinyatakan dengan menaikkan penanda ini.
    const [versiMuat, setVersiMuat] = useState(0);

    useEffect(() => {
        let dilepas = false;

        // Pengambilan data lahir di dalam effect: state baru disetel setelah jawaban
        // server tiba, bukan pada commit render yang sama, dan jawaban yang telat
        // datang setelah layar ditutup dibuang lewat `dilepas`.
        const muat = async () => {
            try {
                const [bookResult, periodResult] = await Promise.all([
                    api<{ data: Book[] }>('/penyusutan/buku'),
                    api<{ data: Period[] }>('/penyusutan'),
                ]);

                if (dilepas) {
                    return;
                }

                setBooks(bookResult.data);
                setPeriods(periodResult.data);
                setError('');
            } catch (caught) {
                if (dilepas) {
                    return;
                }

                setError(
                    errorMessage(caught, 'Data penyusutan belum dapat dimuat.'),
                );
            }
        };

        void muat();

        return () => {
            dilepas = true;
        };
    }, [versiMuat]);
    const muatUlang = () => setVersiMuat((versi) => versi + 1);
    const opsiPost = pilihanPost(periods);
    const propose = async (form: HTMLFormElement) => {
        if (!selected) {
            return;
        }

        const data = new FormData(form);
        setSaving(true);

        try {
            await api('/penyusutan/proposal', {
                method: 'POST',
                body: JSON.stringify({
                    buku_aset_id: selected.id,
                    period_starts_on: data.get('period_starts_on'),
                    period_ends_on: data.get('period_ends_on'),
                    consumption_amount: data.get('consumption_amount') || null,
                }),
            });
            setSelected(null);
            muatUlang();
        } catch (caught) {
            setError(
                errorMessage(caught, 'Proposal penyusutan belum dapat dibuat.'),
            );
        } finally {
            setSaving(false);
        }
    };
    /**
     * Tutup bulan tidak dikerjakan aset demi aset. Buku yang tidak dapat diusulkan
     * dilewati beserta alasannya, jadi hasilnya dilaporkan sebagai ringkasan, bukan
     * sekadar berhasil atau gagal.
     */
    const proposeBulk = async (form: HTMLFormElement) => {
        const data = new FormData(form);
        setSaving(true);
        setBulkResult('');

        try {
            const result = await api<{
                data: { dibuat: number; dilewati: number };
            }>('/penyusutan/proposal-massal', {
                method: 'POST',
                body: JSON.stringify({
                    period_starts_on: data.get('period_starts_on'),
                    period_ends_on: data.get('period_ends_on'),
                }),
            });
            setBulkResult(
                `${result.data.dibuat} proposal dibuat, ${result.data.dilewati} buku dilewati.`,
            );
            setBulkOpen(false);
            muatUlang();
        } catch (caught) {
            setError(
                errorMessage(caught, 'Proposal massal belum dapat dijalankan.'),
            );
        } finally {
            setSaving(false);
        }
    };
    const finalize = async (period: Period) => {
        if (
            !window.confirm(
                `Finalisasi penyusutan ${period.aset_code} untuk periode ini? Nilai final tidak dapat diubah.`,
            )
        ) {
            return;
        }

        try {
            await api(`/penyusutan/${period.id}/finalisasi`, {
                method: 'POST',
            });
            muatUlang();
        } catch (caught) {
            setError(
                errorMessage(caught, 'Penyusutan belum dapat difinalisasi.'),
            );
        }
    };
    const reverse = async (period: Period) => {
        const reason = window.prompt('Alasan koreksi penyusutan:');

        if (!reason) {
            return;
        }

        try {
            await api(`/penyusutan/${period.id}/reversal`, {
                method: 'POST',
                body: JSON.stringify({ reason }),
            });
            muatUlang();
        } catch (caught) {
            setError(errorMessage(caught, 'Penyusutan belum dapat dibalik.'));
        }
    };

    return (
        <div className="space-y-4">
            <Card className="rounded-none border-x-0 shadow-none">
                <CardHeader className="border-b px-5 py-3">
                    <CardTitle>Aset Book aktif</CardTitle>
                    <CardAction>
                        {canCreate && (
                            <Button
                                variant="outline"
                                onClick={() => setBulkOpen(true)}
                                disabled={!books.length}
                            >
                                Proposal seluruh buku
                            </Button>
                        )}
                    </CardAction>
                </CardHeader>
                <CardContent className="px-0">
                    {error && (
                        <p className="text-destructive px-5 py-3 text-sm">
                            {error}
                        </p>
                    )}
                    {bulkResult && (
                        <p className="text-muted-foreground px-5 py-3 text-sm">
                            {bulkResult}
                        </p>
                    )}
                    {!books.length ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada Aset Book aktif
                                </EmptyTitle>
                                <EmptyDescription>
                                    Terima aset dengan profil penyusutan agar
                                    buku aset dibuat.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="divide-y">
                            {books.map((book) => (
                                <div
                                    className="flex items-center justify-between gap-3 px-5 py-3"
                                    key={book.id}
                                >
                                    <div>
                                        <p className="font-medium">
                                            {book.aset_code} · {book.book_code}
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {book.profile_name} · {book.method}{' '}
                                            · {book.frequency}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span>
                                            {book.currency_code}{' '}
                                            {book.net_book_value}
                                        </span>
                                        {canCreate && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setSelected(book)
                                                }
                                            >
                                                Buat proposal
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
            <Card className="rounded-none border-x-0 shadow-none">
                <CardHeader className="border-b px-5 py-3">
                    <CardTitle>Periode penyusutan</CardTitle>
                    <CardAction>
                        {canPost && (
                            <Button
                                variant="outline"
                                onClick={() => setPostOpen(true)}
                                disabled={!opsiPost.books.length}
                            >
                                Post penyusutan
                            </Button>
                        )}
                    </CardAction>
                </CardHeader>
                <CardContent className="px-0">
                    {!periods.length ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada proposal penyusutan
                                </EmptyTitle>
                                <EmptyDescription>
                                    Buat proposal dari Aset Book setelah periode
                                    siap dihitung.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="divide-y">
                            {periods.map((period) => (
                                <div
                                    className="flex items-center justify-between gap-3 px-5 py-3"
                                    key={period.id}
                                >
                                    <div>
                                        <p className="font-medium">
                                            {period.aset_code} ·{' '}
                                            {period.book_code}
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {period.period_starts_on} s.d.{' '}
                                            {period.period_ends_on} ·{' '}
                                            {period.status}
                                            {period.posted_posting_id &&
                                                ' · sudah di-post'}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span>
                                            {period.currency_code}{' '}
                                            {period.amount}
                                        </span>
                                        {period.status === 'proposed' &&
                                            canFinalize && (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        void finalize(period)
                                                    }
                                                >
                                                    Finalisasi
                                                </Button>
                                            )}
                                        {period.status === 'final' &&
                                            !period.reverses_period_id &&
                                            canCorrect && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        void reverse(period)
                                                    }
                                                >
                                                    Balikkan
                                                </Button>
                                            )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
            {postOpen && (
                <DepreciationPostingSheet
                    context={context}
                    books={opsiPost.books}
                    defaultPeriodEnd={opsiPost.akhir}
                    onClose={() => setPostOpen(false)}
                    onPosted={muatUlang}
                />
            )}
            {bulkOpen && (
                <Sheet
                    open
                    onOpenChange={(open) => !open && setBulkOpen(false)}
                >
                    <SheetContent side="right">
                        <SheetHeader>
                            <SheetTitle>Proposal seluruh buku</SheetTitle>
                        </SheetHeader>
                        <form
                            className="space-y-4 p-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void proposeBulk(event.currentTarget);
                            }}
                        >
                            <p className="text-muted-foreground text-sm">
                                Menghitung satu periode untuk seluruh buku aset
                                yang aktif. Buku yang sudah punya periode ini,
                                sudah habis, atau asetnya sudah dilepas akan
                                dilewati. Metode berdasarkan pemakaian tidak
                                ikut karena angka pemakaiannya berbeda tiap
                                aset.
                            </p>
                            <Field>
                                <Input
                                    name="period_starts_on"
                                    label="Periode mulai"
                                    type="date"
                                    required
                                />
                            </Field>
                            <Field>
                                <Input
                                    name="period_ends_on"
                                    label="Periode selesai"
                                    type="date"
                                    required
                                />
                            </Field>
                            <SheetFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setBulkOpen(false)}
                                >
                                    Batal
                                </Button>
                                <Button type="submit" disabled={saving}>
                                    {saving ? 'Menghitung…' : 'Jalankan'}
                                </Button>
                            </SheetFooter>
                        </form>
                    </SheetContent>
                </Sheet>
            )}
            {selected && (
                <Sheet open onOpenChange={(open) => !open && setSelected(null)}>
                    <SheetContent side="right">
                        <SheetHeader>
                            <SheetTitle>Buat proposal penyusutan</SheetTitle>
                        </SheetHeader>
                        <form
                            className="space-y-4 p-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void propose(event.currentTarget);
                            }}
                        >
                            <p className="text-muted-foreground text-sm">
                                {selected.aset_code} · {selected.book_code} ·{' '}
                                {selected.profile_name}
                            </p>
                            <Field>
                                <Input
                                    name="period_starts_on"
                                    label="Periode mulai"
                                    type="date"
                                    required
                                />
                            </Field>
                            <Field>
                                <Input
                                    name="period_ends_on"
                                    label="Periode selesai"
                                    type="date"
                                    required
                                />
                            </Field>
                            {selected.method === 'consumption' && (
                                <Field>
                                    <Input
                                        name="consumption_amount"
                                        label="Nilai penyusutan dari pemakaian"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        required
                                    />
                                </Field>
                            )}
                            <SheetFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setSelected(null)}
                                >
                                    Batal
                                </Button>
                                <Button type="submit" disabled={saving}>
                                    {saving ? 'Menghitung…' : 'Buat proposal'}
                                </Button>
                            </SheetFooter>
                        </form>
                    </SheetContent>
                </Sheet>
            )}
        </div>
    );
}

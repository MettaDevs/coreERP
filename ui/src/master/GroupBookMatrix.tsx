import { useEffect, useRef, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Field, FieldDescription, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { api, errorMessage } from '../api';
import { optionLabel, useMasterOptions } from './useMasterOptions';

type Buku = { id: string; kode: string; nama: string };

type Row = {
    buku_id: string;
    depreciation_profile_id: string;
    alternative_profile_id: string;
    useful_life_periods: string;
    convention: string;
    depreciate: boolean;
    round_off_depreciation: string;
};

const CONVENTIONS: { value: string; label: string }[] = [
    { value: '', label: 'Tanpa penyesuaian' },
    { value: 'full_month', label: 'Bulan perolehan penuh' },
    { value: 'mid_month_1st', label: 'Tengah bulan (awal bulan)' },
    { value: 'mid_month_15th', label: 'Tengah bulan (tanggal 15)' },
    { value: 'mid_quarter', label: 'Tengah kuartal' },
    { value: 'half_year', label: 'Setengah tahun' },
    { value: 'half_year_start_of_year', label: 'Setengah tahun (mulai awal tahun)' },
    { value: 'half_year_next_year', label: 'Setengah tahun (mulai tahun depan)' },
];

/** Berapa periode dalam satu tahun, per frekuensi profil penyusutan. */
const PERIODS_PER_YEAR: Record<string, number> = {
    monthly: 12,
    quarterly: 4,
    half_yearly: 2,
    yearly: 1,
};

/**
 * Masa manfaat disimpan sebagai jumlah periode karena itulah satuan yang dipakai mesin
 * penyusutan. Tetapi "330 periode" tidak terbaca oleh siapa pun, jadi bentuk tahunannya
 * ikut ditampilkan. Konversinya butuh frekuensi, dan frekuensi milik profil — selama
 * profilnya belum dipilih, angkanya memang belum punya arti dalam tahun.
 */
function serviceLifeHint(periods: string, frequency: unknown): string | null {
    const count = Number(periods);
    const perYear = PERIODS_PER_YEAR[String(frequency)];
    if (!Number.isFinite(count) || count <= 0) return null;
    if (!perYear) return 'Setara berapa tahun baru terlihat setelah profil dipilih.';
    const years = count / perYear;

    return `Setara ${years.toFixed(2).replace(/\.00$/, '')} tahun.`;
}

const emptyRow = (bukuId: string): Row => ({
    buku_id: bukuId,
    depreciation_profile_id: '',
    alternative_profile_id: '',
    useful_life_periods: '',
    convention: '',
    depreciate: true,
    round_off_depreciation: '',
});

/**
 * Matriks group x buku, disunting di dalam form group aset.
 *
 * Satu baris berarti "aset dari group ini mendapat buku tersebut", lengkap dengan masa
 * manfaat dan perlakuan periode pertama. Karena itu satu group dapat
 * menyusut komersial dan fiskal sekaligus dengan aturan yang berbeda.
 */
export default function GroupBookMatrix({ groupId, canEdit }: { groupId: string; canEdit: boolean }) {
    const [books, setBooks] = useState<Buku[]>([]);
    const [rows, setRows] = useState<Row[]>([]);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const profiles = useMasterOptions('profil-penyusutan');

    useEffect(() => {
        let cancelled = false;
        Promise.all([
            api<{ data: Buku[] }>('/buku-penyusutan?per_page=100&aktif=true'),
            api<{ data: Record<string, unknown>[] }>(`/group-aset/${groupId}/buku-penyusutan`),
        ]).then(([bookList, matrix]) => {
            if (cancelled) return;
            setBooks(bookList.data);
            setRows(matrix.data.map((row) => ({
                buku_id: String(row.buku_id ?? ''),
                depreciation_profile_id: String(row.depreciation_profile_id ?? ''),
                alternative_profile_id: String(row.alternative_profile_id ?? ''),
                useful_life_periods: row.useful_life_periods === null || row.useful_life_periods === undefined ? '' : String(row.useful_life_periods),
                convention: String(row.convention ?? ''),
                depreciate: Boolean(row.depreciate ?? true),
                round_off_depreciation: row.round_off_depreciation === null || row.round_off_depreciation === undefined ? '' : String(row.round_off_depreciation),
            })));
        }).catch((caught) => {
            if (!cancelled) setError(errorMessage(caught, 'Matriks buku penyusutan belum dapat dimuat.'));
        });
        return () => { cancelled = true; };
    }, [groupId]);

    const label = (id: string) => {
        const book = books.find((item) => item.id === id);
        return book ? `${book.kode} — ${book.nama}` : '';
    };
    const unused = books.filter((book) => !rows.some((row) => row.buku_id === book.id));
    const patch = (index: number, changes: Partial<Row>) =>
        setRows((current) => current.map((row, i) => (i === index ? { ...row, ...changes } : row)));

    async function save() {
        setSaving(true);
        setError('');
        setSaved(false);
        try {
            // Kiriman memuat daftar penuh; baris yang dihapus dari layar ikut diarsipkan.
            await api(`/group-aset/${groupId}/buku-penyusutan`, {
                method: 'PUT',
                body: JSON.stringify({
                    rows: rows.map((row) => ({
                        buku_id: row.buku_id,
                        depreciation_profile_id: row.depreciation_profile_id || null,
                        alternative_profile_id: row.alternative_profile_id || null,
                        useful_life_periods: row.useful_life_periods === '' ? null : Number(row.useful_life_periods),
                        convention: row.convention || null,
                        depreciate: row.depreciate,
                        round_off_depreciation: row.round_off_depreciation === '' ? null : Number(row.round_off_depreciation),
                    })),
                }),
            });
            setSaved(true);
        } catch (caught) {
            setError(errorMessage(caught, 'Matriks belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div ref={containerRef} className="space-y-4 border-t px-5 py-4">
            <div>
                <p className="font-semibold">Buku penyusutan</p>
                <p className="text-sm text-muted-foreground">
                    Aset dari group ini akan mendapat satu buku untuk tiap baris di bawah. Buku komersial dan fiskal boleh memakai metode, masa manfaat, dan tanggal berlaku yang berbeda.
                </p>
            </div>

            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Belum ada buku. Aset dari group ini belum dapat ditempatkan sampai matriks memiliki buku dan profil yang bisa dihitung.
                </p>
            )}

            {rows.map((row, index) => (
                <div key={row.buku_id} className="space-y-3 rounded-md border p-3">
                    <div className="flex items-center justify-between gap-3">
                        <span className="font-medium">{label(row.buku_id) || 'Buku tidak dikenal'}</span>
                        {canEdit && (
                            <Button variant="outline" size="sm" type="button" onClick={() => setRows(rows.filter((_, i) => i !== index))}>
                                Hapus
                            </Button>
                        )}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field>
                            <Select
                                label="Profil utama untuk group ini"
                                items={profiles.options.map(optionLabel)}
                                value={profiles.options.filter((item) => item.id === row.depreciation_profile_id).map(optionLabel)[0]}
                                placeholder="Ikuti profil utama buku"
                                searchPlaceholder="Cari profil penyusutan"
                                emptyMessage="Profil penyusutan tidak ditemukan."
                                ariaLabel="Pilih profil utama untuk group ini"
                                portalContainer={containerRef}
                                onValueChange={(item) => patch(index, { depreciation_profile_id: profiles.options.find((option) => optionLabel(option) === item)?.id ?? '' })}
                            />
                            <FieldDescription>{profiles.error || 'Kosong berarti memakai profil utama pada Buku penyusutan. Server akan memeriksa masa manfaat, metode, frekuensi, dan tanggal berlakunya sebelum aset ditempatkan.'}</FieldDescription>
                        </Field>
                        <Field>
                            <Select
                                label="Profil pengganti (opsional)"
                                items={profiles.options.map(optionLabel)}
                                value={profiles.options.filter((item) => item.id === row.alternative_profile_id).map(optionLabel)[0]}
                                placeholder="Tanpa pengganti"
                                searchPlaceholder="Cari profil pengganti"
                                emptyMessage="Profil penyusutan tidak ditemukan."
                                ariaLabel="Pilih profil pengganti"
                                portalContainer={containerRef}
                                onValueChange={(item) => patch(index, { alternative_profile_id: profiles.options.find((option) => optionLabel(option) === item)?.id ?? '' })}
                            />
                            <FieldDescription>
                                Dipakai begitu saldo menurun menghasilkan angka lebih kecil daripada garis lurus sisa umur, agar aset tetap habis di akhir masa manfaat.
                            </FieldDescription>
                        </Field>
                        <Field>
                            <Input
                                id={`life-${row.buku_id}`}
                                label="Masa manfaat (jumlah periode)"
                                type="number"
                                min={1}
                                disabled={!canEdit}
                                value={row.useful_life_periods}
                                onChange={(event) => patch(index, { useful_life_periods: event.target.value })}
                            />
                            <FieldDescription>
                                {serviceLifeHint(
                                    row.useful_life_periods,
                                    profiles.options.find((option) => option.id === row.depreciation_profile_id)?.frequency,
                                ) ?? 'Kosong berarti memakai masa manfaat pada profil penyusutan.'}
                            </FieldDescription>
                        </Field>
                        <Field>
                            <Select
                                label="Perlakuan periode pertama"
                                items={CONVENTIONS.map((item) => item.label)}
                                value={CONVENTIONS.find((item) => item.value === row.convention)?.label}
                                placeholder="Pilih perlakuan"
                                searchPlaceholder="Cari perlakuan"
                                emptyMessage="Perlakuan tidak ditemukan."
                                ariaLabel="Pilih perlakuan periode pertama"
                                portalContainer={containerRef}
                                onValueChange={(item) => patch(index, { convention: CONVENTIONS.find((c) => c.label === item)?.value ?? '' })}
                            />
                            <FieldDescription>Dihitung dari tanggal aset mulai digunakan.</FieldDescription>
                        </Field>
                        <Field>
                            <Input
                                id={`round-off-${row.buku_id}`}
                                label="Kelipatan pembulatan penyusutan"
                                type="number"
                                min={0}
                                step={0.01}
                                disabled={!canEdit}
                                value={row.round_off_depreciation}
                                onChange={(event) => patch(index, { round_off_depreciation: event.target.value })}
                            />
                            <FieldDescription>Kosong mengikuti Book. Periode terakhir tidak dibulatkan.</FieldDescription>
                        </Field>
                        <Field orientation="horizontal">
                            <Switch id={`depreciate-${row.buku_id}`} disabled={!canEdit} checked={row.depreciate} onCheckedChange={(checked) => patch(index, { depreciate: checked })} />
                            <FieldLabel htmlFor={`depreciate-${row.buku_id}`}>Hitung penyusutan</FieldLabel>
                        </Field>
                    </div>
                </div>
            ))}

            {canEdit && unused.length > 0 && (
                <div className="sm:w-72">
                    <Select
                        items={unused.map((book) => `${book.kode} — ${book.nama}`)}
                        placeholder="Tambah buku penyusutan"
                        searchPlaceholder="Cari buku"
                        emptyMessage="Buku tidak ditemukan."
                        ariaLabel="Tambah buku penyusutan"
                        portalContainer={containerRef}
                        onValueChange={(item) => {
                            const book = unused.find((candidate) => `${candidate.kode} — ${candidate.nama}` === item);
                            if (book) setRows([...rows, emptyRow(book.id)]);
                        }}
                    />
                </div>
            )}

            {error && <p className="text-sm text-destructive">{error}</p>}
            {saved && <p className="text-sm text-muted-foreground">Matriks tersimpan.</p>}

            {canEdit && (
                <Button type="button" disabled={saving} onClick={() => void save()}>
                    {saving ? 'Menyimpan…' : 'Simpan matriks'}
                </Button>
            )}
        </div>
    );
}

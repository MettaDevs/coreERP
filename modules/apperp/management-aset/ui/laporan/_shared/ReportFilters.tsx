import { useEffect, useMemo, useRef, useState } from 'react';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { MultiSelect } from '@apperp/ui/multi-select';
import { Select } from '@apperp/ui/select';
import { api } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';

/*
 * Filter yang dipakai bersama oleh halaman laporan. Halaman memilih filter mana yang ia
 * butuhkan dan menaruhnya di dalam `ReportFilterBar`; tiap filter mengikat satu parameter
 * laporan lewat `bindFilter()` dari `useReportData`.
 */

export type FilterProps = {
    value: string | undefined;
    onChange: (value: string) => void;
};

/** Filter pilihan banyak: beberapa pilihan pada satu filter berarti "atau" (K-28). */
export type MultiFilterProps = {
    value: string[];
    onChange: (value: string[]) => void;
};

/**
 * Nilai pilihan "semua". `Select` baru menampilkan label pilihan terpilih bila nilainya
 * tidak kosong, jadi "semua" tidak boleh bernilai string kosong; di luar komponen ini ia
 * tetap diterjemahkan menjadi parameter yang tidak diisi.
 */
const ALL = '__all__';

type MasterFilterProps = MultiFilterProps & {
    resource: string;
    label: string;
    /** Kalimat untuk pengguna bila pilihannya tidak dapat dimuat, biasanya karena belum punya akses lihat. */
    unavailable: string;
};

/**
 * Filter master pilihan banyak. Tanpa pilihan berarti semua; beberapa pilihan berarti aset yang cocok
 * dengan salah satunya, dan kepala laporan menyebut nama setiap pilihan.
 */
function MasterFilter({
    resource,
    label,
    unavailable,
    value,
    onChange,
}: MasterFilterProps) {
    const master = useMasterOptions(resource);
    const items = useMemo(
        () =>
            master.options.map((option) => ({
                value: option.id,
                label: optionLabel(option),
            })),
        [master.options],
    );

    return (
        <Field className="w-full sm:w-56">
            <MultiSelect
                label={label}
                items={items}
                value={value}
                onValueChange={onChange}
                searchPlaceholder={`Cari ${label.toLowerCase()}`}
                emptyMessage={`${label} tidak ditemukan.`}
            />
            {master.error && <FieldDescription>{unavailable}</FieldDescription>}
        </Field>
    );
}

export function AssetGroupFilter(props: MultiFilterProps) {
    return (
        <MasterFilter
            {...props}
            resource="group-aset"
            label="Group aset"
            unavailable="Pilihan group aset tidak dapat dimuat. Minta administrator memberi Anda akses lihat group aset."
        />
    );
}

export function AssetTypeFilter(props: MultiFilterProps) {
    return (
        <MasterFilter
            {...props}
            resource="jenis-aset"
            label="Jenis aset"
            unavailable="Pilihan jenis aset tidak dapat dimuat. Minta administrator memberi Anda akses lihat jenis aset."
        />
    );
}

export function FiscalClassificationFilter(props: MultiFilterProps) {
    return (
        <MasterFilter
            {...props}
            resource="reference-data/kelompok-harta-fiskal"
            label="Kelompok harta fiskal"
            unavailable="Pilihan kelompok harta fiskal tidak dapat dimuat. Minta administrator memberi Anda akses lihat group aset."
        />
    );
}

export function LocationFilter(props: MultiFilterProps) {
    return (
        <MasterFilter
            {...props}
            resource="lokasi-aset"
            label="Lokasi"
            unavailable="Pilihan lokasi tidak dapat dimuat. Minta administrator memberi Anda akses lihat lokasi aset."
        />
    );
}

export function ConditionFilter(props: MultiFilterProps) {
    return (
        <MasterFilter
            {...props}
            resource="kondisi-aset"
            label="Kondisi"
            unavailable="Pilihan kondisi tidak dapat dimuat. Minta administrator memberi Anda akses lihat kondisi aset."
        />
    );
}

/**
 * Filter buku penyusutan. Tanpa pilihan, laporan memakai buku komersial saja: buku fiskal
 * menyusutkan aset yang sama, jadi menjumlahkan semua buku membuat totalnya dobel.
 */
export function DepreciationBookFilter({ value, onChange }: FilterProps) {
    const master = useMasterOptions('buku-penyusutan');
    const items = useMemo(
        () => [
            { value: ALL, label: 'Semua buku komersial' },
            ...master.options.map((option) => ({
                value: option.id,
                label: optionLabel(option),
            })),
        ],
        [master.options],
    );

    return (
        <Field className="w-full sm:w-52">
            <Select
                label="Buku penyusutan"
                items={items}
                value={value || ALL}
                onValueChange={(next) =>
                    onChange(next === null || next === ALL ? '' : next)
                }
                searchPlaceholder="Cari buku penyusutan"
                emptyMessage="Buku penyusutan tidak ditemukan."
            />
            {master.error && (
                <FieldDescription>
                    Pilihan buku penyusutan tidak dapat dimuat. Minta
                    administrator memberi Anda akses lihat buku penyusutan.
                </FieldDescription>
            )}
        </Field>
    );
}

type AssetOption = {
    id: string;
    kode: string;
    nama?: string | null;
};

const assetLabel = (asset: AssetOption) =>
    `${asset.kode} — ${asset.nama ?? 'Tanpa nama'}`;

/**
 * Filter satu aset, dicari di server.
 *
 * Daftar aset tenant bisa ribuan, jadi pilihannya tidak dimuat sekaligus: pengguna
 * mengetik kode atau nama, dan `GET /aset?q=` yang mencarikannya.
 */
export function AssetFilter({ value, onChange }: FilterProps) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<AssetOption[]>([]);
    const [searching, setSearching] = useState(false);
    const [failed, setFailed] = useState(false);
    // Aset terpilih disimpan tersendiri supaya labelnya tetap tampil walau hasil pencarian
    // berikutnya tidak lagi memuatnya.
    const [selected, setSelected] = useState<AssetOption | null>(null);
    const timer = useRef<number | undefined>(undefined);
    const latest = useRef(0);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    // Satu permintaan setelah pengguna berhenti mengetik. Jawaban yang datang terlambat
    // untuk kata kunci lama dibuang supaya tidak menimpa hasil yang lebih baru.
    const search = (next: string) => {
        window.clearTimeout(timer.current);
        const q = next.trim();
        setQuery(q);

        if (q === '') {
            setResults([]);
            setSearching(false);

            return;
        }

        setSearching(true);
        timer.current = window.setTimeout(() => {
            const request = ++latest.current;

            api<{ data: AssetOption[] }>(`/aset?q=${encodeURIComponent(q)}`)
                .then((response) => {
                    if (request === latest.current) {
                        setResults(response.data);
                        setFailed(false);
                        setSearching(false);
                    }
                })
                .catch(() => {
                    if (request === latest.current) {
                        setResults([]);
                        setFailed(true);
                        setSearching(false);
                    }
                });
        }, 250);
    };

    const current = selected && selected.id === value ? selected : null;
    const items = useMemo(() => {
        const shown =
            current && !results.some((asset) => asset.id === current.id)
                ? [current, ...results]
                : results;

        return [
            { value: ALL, label: 'Semua aset' },
            ...shown.map((asset) => ({
                value: asset.id,
                label: assetLabel(asset),
            })),
        ];
    }, [current, results]);

    return (
        <Field className="w-full sm:w-64">
            <Select
                label="Aset"
                items={items}
                value={value || ALL}
                onSearchChange={search}
                onValueChange={(next) => {
                    const id = next === null || next === ALL ? '' : next;

                    setSelected(
                        id === ''
                            ? null
                            : (results.find((asset) => asset.id === id) ??
                                  current),
                    );
                    onChange(id);
                }}
                searchPlaceholder="Ketik kode atau nama aset"
                emptyMessage={
                    searching
                        ? 'Mencari aset…'
                        : query
                          ? 'Aset tidak ditemukan.'
                          : 'Ketik kode atau nama aset untuk mencarinya.'
                }
            />
            {failed && (
                <FieldDescription>
                    Daftar aset tidak dapat dimuat. Minta administrator memberi
                    Anda akses lihat aset.
                </FieldDescription>
            )}
        </Field>
    );
}

/** Satu bulan, untuk laporan yang dihitung per periode seperti penyusutan. */
export function PeriodFilter({ value, onChange }: FilterProps) {
    return (
        <div className="w-full sm:w-44">
            <Input
                label="Periode"
                type="month"
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}

/** Satu tanggal; rentang dibentuk dua filter ini, misalnya "Dari tanggal" dan "Sampai tanggal". */
export function DateFilter({
    label,
    value,
    onChange,
}: FilterProps & { label: string }) {
    return (
        <div className="w-full sm:w-44">
            <Input
                label={label}
                type="date"
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}

import { useMemo } from 'react';
import { DatePicker } from '@apperp/ui/date-picker';
import { FieldDescription } from '@apperp/ui/field';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { MultiSelect } from '@apperp/ui/multi-select';
import { Select } from '@apperp/ui/select';
import RecordLookup from '../../_shared/RecordLookup';
import RecordMultiLookup from '../../_shared/RecordMultiLookup';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { useRecordOptions } from '../../use-record-list';

/*
 * Kontrol yang dipakai bersama oleh mesin filter laporan. `ReportFilterEngine` memilih kontrol
 * dari profil, lalu mengikat parameter laporan lewat `useReportData`.
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

/** Pilihan tunggal dengan nilai bawaan yang juga dipakai server laporan. */
export function ChoiceFilter({
    value,
    onChange,
    label,
    items,
    defaultValue,
}: FilterProps & {
    label: string;
    items: readonly { value: string; label: string }[];
    defaultValue: string;
}) {
    return (
        <FormControl className="w-full">
            <Select
                label={label}
                items={[...items]}
                value={value || defaultValue}
                onValueChange={(next) => onChange(next ?? defaultValue)}
            />
        </FormControl>
    );
}

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
export function MasterFilter({
    resource,
    label,
    unavailable,
    value,
    onChange,
}: MasterFilterProps) {
    const master = useMasterOptions(resource, '', value);
    const items = useMemo(
        () =>
            master.options.map((option) => ({
                value: option.id,
                label: optionLabel(option),
            })),
        [master.options],
    );

    return (
        <FormControl className="w-full">
            <RecordMultiLookup
                source={master}
                allowCreate={false}
                label={label}
                items={items}
                value={value}
                onValueChange={onChange}
                placeholder={`Cari ${label.toLowerCase()}`}
                emptyMessage={`${label} tidak ditemukan.`}
            />
            {master.error && <FieldDescription>{unavailable}</FieldDescription>}
        </FormControl>
    );
}

/**
 * Filter buku penyusutan. Laporan keuangan memakai buku komersial bila tidak dipilih;
 * daftar perolehan memakai seluruh register. Profil menerangkan nilai kosong lewat `emptyLabel`.
 */
export function DepreciationBookFilter({
    value,
    onChange,
    emptyLabel = 'Semua buku komersial',
}: FilterProps & { emptyLabel?: string }) {
    const master = useMasterOptions('buku-penyusutan', '', value ?? '');
    const items = useMemo(
        () => [
            { value: '', label: emptyLabel },
            ...master.options.map((option) => ({
                value: option.id,
                label: optionLabel(option),
            })),
        ],
        [master.options, emptyLabel],
    );

    return (
        <FormControl className="w-full">
            <RecordLookup
                source={master}
                allowCreate={false}
                label="Buku penyusutan"
                items={items}
                value={value || null}
                onValueChange={(next) => onChange(next ?? '')}
                placeholder="Cari buku penyusutan"
                emptyMessage="Buku penyusutan tidak ditemukan."
            />
            {master.error && (
                <FieldDescription>
                    Pilihan buku penyusutan tidak dapat dimuat. Minta
                    administrator memberi Anda akses lihat buku penyusutan.
                </FieldDescription>
            )}
        </FormControl>
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
    const source = useRecordOptions<AssetOption>(
        '/aset',
        assetLabel,
        value ?? '',
    );

    return (
        <FormControl className="w-full" error={source.error}>
            <RecordLookup
                source={source}
                allowCreate={false}
                label="Aset"
                value={value || null}
                items={[
                    { value: '', label: 'Semua aset' },
                    ...source.options.map((asset) => ({
                        value: asset.id,
                        label: assetLabel(asset),
                    })),
                ]}
                onValueChange={(next) => onChange(next ?? '')}
                placeholder="Ketik kode atau nama aset"
                emptyMessage={
                    source.loading
                        ? 'Mencari aset…'
                        : source.selectProps.searchValue
                          ? 'Aset tidak ditemukan.'
                          : 'Ketik kode atau nama aset untuk mencarinya.'
                }
            />
        </FormControl>
    );
}

/** Filter bulan; dipakai untuk periode penyusutan dan rentang proyeksi. */
export function MonthFilter({
    label,
    value,
    onChange,
}: FilterProps & { label: string }) {
    return (
        <FormControl className="w-full">
            <Input
                label={label}
                type="month"
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormControl>
    );
}

/** Filter banyak pilihan statis, seperti status proses laporan. */
export function OptionsFilter({
    label,
    items,
    value,
    onChange,
}: MultiFilterProps & {
    label: string;
    items: readonly { value: string; label: string }[];
}) {
    return (
        <FormControl className="w-full">
            <MultiSelect
                label={label}
                items={[...items]}
                value={value}
                onValueChange={onChange}
                searchPlaceholder={`Cari ${label.toLowerCase()}`}
                emptyMessage={`${label} tidak ditemukan.`}
            />
        </FormControl>
    );
}

/** Satu tanggal; rentang dibentuk dua filter ini, misalnya "Dari tanggal" dan "Sampai tanggal". */
export function DateFilter({
    label,
    value,
    onChange,
}: FilterProps & { label: string }) {
    return (
        <FormControl className="w-full">
            <DatePicker
                label={label}
                value={value ?? ''}
                onValueChange={onChange}
            />
        </FormControl>
    );
}

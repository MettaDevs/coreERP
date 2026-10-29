import { Alert, AlertDescription } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { Paperclip, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { fileExtension, fileSize } from '@/lib/record-files';

export type DraftAttachmentLimits = {
    extensions: string[];
    max_kb: number;
    max_files: number;
    max_total_kb: number;
};

/** Pilihan berkas tetap lokal sampai form induk mengirim seluruh agregatnya. */
export function DraftRecordAttachments({
    files,
    onChange,
    limits,
    disabled = false,
    error,
}: {
    files: File[];
    onChange: (files: File[]) => void;
    limits: DraftAttachmentLimits;
    disabled?: boolean;
    error?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [selectionError, setSelectionError] = useState<string | null>(null);
    const totalBytes = files.reduce((total, file) => total + file.size, 0);
    const columns: DataTableColumn<File>[] = [
        {
            id: 'name',
            header: 'Nama berkas',
            cell: (file) => <DataTableText>{file.name}</DataTableText>,
        },
        {
            id: 'size',
            header: 'Ukuran',
            width: 100,
            cell: (file) => fileSize(file.size),
        },
    ];

    function select(selected: File[]) {
        if (disabled || selected.length === 0) {
            return;
        }

        const invalid = selected.find(
            (file) =>
                !limits.extensions.includes(fileExtension(file.name)) ||
                file.size > limits.max_kb * 1024,
        );

        if (invalid) {
            setSelectionError(
                `${invalid.name}: pilih ${limits.extensions.join(', ').toUpperCase()} dengan ukuran maksimal ${fileSize(limits.max_kb * 1024)} per berkas.`,
            );

            return;
        }

        if (files.length + selected.length > limits.max_files) {
            setSelectionError(
                `Maksimal ${limits.max_files} berkas dapat dilampirkan sekaligus.`,
            );

            return;
        }

        const selectedBytes = selected.reduce(
            (total, file) => total + file.size,
            0,
        );

        if (totalBytes + selectedBytes > limits.max_total_kb * 1024) {
            setSelectionError(
                `Ukuran seluruh lampiran melebihi ${fileSize(limits.max_total_kb * 1024)}. Pilih lebih sedikit berkas atau keluarkan berkas yang sudah dipilih.`,
            );

            return;
        }

        setSelectionError(null);
        onChange([...files, ...selected]);
    }

    return (
        <section className="space-y-3" aria-label="Lampiran belum disimpan">
            <input
                ref={input}
                type="file"
                multiple
                hidden
                disabled={disabled}
                aria-label="Pilih berkas lampiran"
                accept={limits.extensions
                    .map((extension) => `.${extension}`)
                    .join(',')}
                onChange={(event) => {
                    select(Array.from(event.currentTarget.files ?? []));
                    event.currentTarget.value = '';
                }}
            />
            <Button
                type="button"
                variant="ghost"
                size="command"
                disabled={disabled || files.length >= limits.max_files}
                onClick={() => input.current?.click()}
            >
                <Paperclip aria-hidden="true" />
                Lampirkan berkas
            </Button>
            {(selectionError || error) && (
                <Alert variant="destructive">
                    <AlertDescription>
                        {selectionError || error}
                    </AlertDescription>
                </Alert>
            )}
            {files.length > 0 ? (
                <DataTable
                    columns={columns}
                    data={files}
                    getRowKey={(file) => String(files.indexOf(file))}
                    getRowLabel={(file) => file.name}
                    scrollMode="inline"
                    actionsPlacement="inline"
                    actions={[
                        {
                            id: 'remove',
                            label: 'Keluarkan',
                            icon: <Trash2 aria-hidden="true" />,
                            destructive: true,
                        },
                    ]}
                    onRowAction={(_, file) => {
                        if (!disabled) {
                            const index = files.indexOf(file);
                            onChange(
                                files.filter(
                                    (_, position) => position !== index,
                                ),
                            );
                            setSelectionError(null);
                        }
                    }}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    Belum ada lampiran. Pilih berkas untuk disimpan bersama
                    isian lainnya.
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                {limits.extensions.join(', ').toUpperCase()} · Maksimal{' '}
                {fileSize(limits.max_kb * 1024)} per berkas · Total{' '}
                {fileSize(totalBytes)} dari{' '}
                {fileSize(limits.max_total_kb * 1024)}. Berkas yang dipilih
                belum tersimpan sampai Anda memilih Simpan.
            </p>
        </section>
    );
}

import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';

type Report = {
    status: string;
    created_count: number;
    unchanged_count: number;
    rejected: string[];
};

export default function SupplierImport({
    legalEntities,
}: {
    legalEntities: { id: string; name: string }[];
}) {
    const [entity, setEntity] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [report, setReport] = useState<Report | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    async function run(apply: boolean) {
        if (!file || !entity || busy) {
            return;
        }

        setBusy(true);
        setError('');
        setFieldErrors({});

        try {
            const body = new FormData();
            body.append('file', file);
            body.append('legal_entity_id', entity);
            body.append('apply', apply ? '1' : '0');
            const result = await apiJson<{ data: Report }>(
                '/api/v1/vendors/imports',
                { method: 'POST', body },
            );
            setReport(result.data);

            if (result.data.status === 'applied') {
                router.reload({ only: ['vendors'] });
            }
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setFieldErrors(caught.errors);
            }

            setReport(null);
            setError(errorText(caught, 'Impor supplier gagal.'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Impor Supplier BO</CardTitle>
                <FormErrorHeader
                    message={
                        report?.status === 'rejected'
                            ? `Berkas ditolak. ${report.rejected.join(' ')}`
                            : error
                    }
                    errors={fieldErrors}
                    operation="Impor supplier belum berhasil"
                />
                <CardDescription>
                    Unduh Supplier (CSV) dari Setup Finance Feed BO. Setelah
                    impor, sinkronkan vendor di BO lalu petakan ke supplier
                    untuk Faktur dan Kartu Hutang. Vendor baru memakai nomor
                    otomatis CoreERP.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div
                        className="w-full sm:w-64"
                        data-error-field={'legal_entity_id'}
                    >
                        <NativeSelect
                            data-error-field="legal_entity_id"
                            aria-invalid={!!fieldErrors.legal_entity_id}
                            label="Perusahaan tujuan"
                            value={entity}
                            disabled={busy}
                            onChange={(event) => {
                                setEntity(event.target.value);
                                setReport(null);
                            }}
                        >
                            <NativeSelectOption value="">
                                Pilih perusahaan
                            </NativeSelectOption>
                            {legalEntities.map((item) => (
                                <NativeSelectOption
                                    key={item.id}
                                    value={item.id}
                                >
                                    {item.name}
                                </NativeSelectOption>
                            ))}
                        </NativeSelect>
                        <FieldError>
                            {fieldErrors.legal_entity_id?.join(' ')}
                        </FieldError>
                    </div>
                    <div
                        className="w-full sm:max-w-sm"
                        data-error-field={'file'}
                    >
                        <label htmlFor="supplier-csv" className="text-sm">
                            Berkas supplier BO
                        </label>
                        <Input
                            data-error-field="file"
                            aria-invalid={!!fieldErrors.file}
                            id="supplier-csv"
                            type="file"
                            accept=".csv,.txt"
                            disabled={busy}
                            onChange={(event) => {
                                setFile(event.target.files?.[0] ?? null);
                                setReport(null);
                            }}
                        />
                        <FieldError>{fieldErrors.file?.join(' ')}</FieldError>
                    </div>
                    <Button
                        variant="outline"
                        disabled={busy || !file || !entity}
                        onClick={() => void run(false)}
                    >
                        Pratinjau
                    </Button>
                    <Button
                        disabled={busy || report?.status !== 'preview'}
                        onClick={() => void run(true)}
                    >
                        {busy ? 'Memproses…' : 'Terapkan impor'}
                    </Button>
                </div>
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                {report && (
                    <div role="status" className="space-y-2 text-sm">
                        <p>
                            {report.status === 'applied'
                                ? 'Impor selesai'
                                : report.status === 'rejected'
                                  ? 'Berkas ditolak; tidak ada vendor yang diubah'
                                  : 'Pratinjau'}
                            : {report.created_count} vendor baru,{' '}
                            {report.unchanged_count} sudah tersedia.
                        </p>
                        {report.rejected.length > 0 && (
                            <ul className="list-disc pl-5 text-destructive">
                                {report.rejected.map((message, index) => (
                                    <li key={index}>{message}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

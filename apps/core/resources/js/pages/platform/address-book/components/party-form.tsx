import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
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
import { Textarea } from '@apperp/ui/textarea';
import { useRef, useState } from 'react';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import type {
    DirectoryMetadata,
    Party,
    PartyDetail,
    PartyProfile,
} from '../types';

type ProfileField = {
    key: keyof PartyProfile;
    label: string;
    type?: 'date' | 'number' | 'select';
};
export const commonProfileFields: ProfileField[] = [
    { key: 'known_as', label: 'Nama panggilan / alias' },
    { key: 'language', label: 'Bahasa', type: 'select' },
];
export const organizationProfileFields: ProfileField[] = [
    { key: 'organization_number', label: 'Nomor registrasi organisasi / NIB' },
    { key: 'number_of_employees', label: 'Jumlah karyawan', type: 'number' },
    { key: 'abc_code', label: 'Klasifikasi ABC', type: 'select' },
    { key: 'duns_number', label: 'Nomor DUNS (jika ada)' },
];
export const personProfileFields: ProfileField[] = [
    { key: 'personal_title', label: 'Gelar depan' },
    { key: 'first_name', label: 'Nama depan' },
    { key: 'middle_name', label: 'Nama tengah' },
    { key: 'last_name', label: 'Nama belakang' },
    { key: 'personal_suffix', label: 'Gelar belakang' },
    { key: 'initials', label: 'Inisial' },
    { key: 'professional_title', label: 'Profesi / jabatan' },
    { key: 'professional_suffix', label: 'Sertifikasi profesi' },
    { key: 'gender', label: 'Jenis kelamin', type: 'select' },
    { key: 'marital_status', label: 'Status pernikahan', type: 'select' },
    { key: 'birth_date', label: 'Tanggal lahir', type: 'date' },
    { key: 'anniversary_date', label: 'Tanggal pernikahan', type: 'date' },
    { key: 'number_of_children', label: 'Jumlah anak', type: 'number' },
    { key: 'interests', label: 'Minat' },
];

export function PartyForm({
    party,
    metadata,
    onClose,
    onSaved,
}: {
    party: PartyDetail | null;
    metadata: DirectoryMetadata;
    onClose: () => void;
    onSaved: (party: Party) => void;
}) {
    const [form, setForm] = useState<Record<string, string | number | null>>(
        () => ({
            name: party?.name ?? '',
            search_name: party?.search_name ?? '',
            type: party?.type ?? 'organization',
            status: party?.status ?? 'active',
            ...Object.fromEntries(
                [
                    ...commonProfileFields,
                    ...organizationProfileFields,
                    ...personProfileFields,
                    { key: 'memo' },
                ].map((field) => [
                    field.key,
                    party?.[field.key as keyof PartyProfile] ?? null,
                ]),
            ),
        }),
    );
    const [bookIds, setBookIds] = useState(party?.address_book_ids ?? []);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const creationKey = useRef(crypto.randomUUID());
    const contentRef = useRef<HTMLDivElement>(null);
    const set = (key: string, value: string | number | null) =>
        setForm((current) => ({ ...current, [key]: value }));
    const internalOrganization = Boolean(party?.organization_id);
    async function save(event: React.FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError('');
        setErrors({});
        const data = Object.fromEntries(
            Object.entries(form).map(([key, value]) => [
                key,
                value === '' && !['name', 'type', 'status'].includes(key)
                    ? null
                    : value,
            ]),
        );
        try {
            const result = await apiJson<{ data: Party }>(
                party ? `/api/v1/parties/${party.id}` : '/api/v1/parties',
                {
                    method: party ? 'PATCH' : 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Idempotency-Key': creationKey.current,
                    },
                    body: JSON.stringify({
                        ...data,
                        address_book_ids: bookIds,
                        ...(party ? { version: party.version } : {}),
                    }),
                },
            );
            onSaved(result.data);
        } catch (caught) {
            setError(errorText(caught, 'Pihak belum dapat disimpan.'));
            if (caught instanceof CoreApiError) setErrors(caught.errors);
        } finally {
            setBusy(false);
        }
    }
    const fields = (items: ProfileField[]) => (
        <div className="grid gap-4 sm:grid-cols-2">
            {items.map((field) => (
                <Field key={field.key}>
                    {field.type === 'select' ? (
                        <NativeSelect
                            label={field.label}
                            value={String(form[field.key] ?? '')}
                            disabled={busy}
                            onChange={(event) =>
                                set(field.key, event.target.value || null)
                            }
                        >
                            <NativeSelectOption value="">
                                Belum diisi
                            </NativeSelectOption>
                            {(metadata.referenceValues[field.key] ?? []).map(
                                (value) => (
                                    <NativeSelectOption
                                        key={value.code}
                                        value={value.code}
                                    >
                                        {value.name}
                                    </NativeSelectOption>
                                ),
                            )}
                        </NativeSelect>
                    ) : (
                        <Input
                            label={field.label}
                            type={field.type ?? 'text'}
                            min={field.type === 'number' ? 0 : undefined}
                            value={String(form[field.key] ?? '')}
                            disabled={busy}
                            onChange={(event) =>
                                set(
                                    field.key,
                                    field.type === 'number'
                                        ? event.target.value === ''
                                            ? null
                                            : Number(event.target.value)
                                        : event.target.value,
                                )
                            }
                        />
                    )}
                    <FieldError>{errors[field.key]?.[0]}</FieldError>
                </Field>
            ))}
        </div>
    );

    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <SheetContent
                ref={contentRef}
                className="flex flex-col sm:max-w-3xl"
            >
                <SheetHeader>
                    <SheetTitle>
                        {party ? 'Ubah pihak' : 'Tambah pihak'}
                    </SheetTitle>
                    <SheetDescription>
                        Nama, alamat, dan kontak pihak dipakai bersama oleh akun
                        bisnisnya.
                    </SheetDescription>
                </SheetHeader>
                <form
                    id="party-form"
                    onSubmit={(event) => void save(event)}
                    className="min-h-0 flex-1 space-y-6 overflow-y-auto px-4 pb-4"
                >
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <FieldSet>
                        <FieldLegend>Identitas</FieldLegend>
                        <FieldGroup>
                            <Input
                                label="Nomor pihak"
                                value={
                                    party?.number ??
                                    'Diterbitkan otomatis setelah disimpan'
                                }
                                readOnly
                            />
                            <Input
                                label="Nama lengkap"
                                required
                                value={String(form.name)}
                                readOnly={internalOrganization}
                                disabled={busy}
                                onChange={(event) =>
                                    set('name', event.target.value)
                                }
                            />
                            <FieldError>{errors.name?.[0]}</FieldError>
                            {party ? (
                                <Input
                                    label="Jenis pihak"
                                    readOnly
                                    value={
                                        metadata.partyTypes.find(
                                            (type) => type.code === party.type,
                                        )?.name ?? party.type
                                    }
                                />
                            ) : (
                                <NativeSelect
                                    label="Jenis pihak"
                                    required
                                    value={String(form.type)}
                                    disabled={busy}
                                    onChange={(event) =>
                                        set('type', event.target.value)
                                    }
                                >
                                    {metadata.partyTypes
                                        .filter((type) =>
                                            ['person', 'organization'].includes(
                                                type.code,
                                            ),
                                        )
                                        .map((type) => (
                                            <NativeSelectOption
                                                key={type.code}
                                                value={type.code}
                                            >
                                                {type.name}
                                            </NativeSelectOption>
                                        ))}
                                </NativeSelect>
                            )}
                            <Input
                                label="Nama pencarian"
                                value={String(form.search_name ?? '')}
                                disabled={busy}
                                onChange={(event) =>
                                    set('search_name', event.target.value)
                                }
                            />
                            <NativeSelect
                                label="Status"
                                required
                                value={String(form.status)}
                                disabled={busy}
                                onChange={(event) =>
                                    set('status', event.target.value)
                                }
                            >
                                <NativeSelectOption value="active">
                                    Aktif
                                </NativeSelectOption>
                                <NativeSelectOption value="inactive">
                                    Nonaktif
                                </NativeSelectOption>
                            </NativeSelect>
                            {internalOrganization && (
                                <FieldDescription>
                                    Nama organisasi diubah dari halaman
                                    Organisasi.
                                </FieldDescription>
                            )}
                        </FieldGroup>
                    </FieldSet>
                    <FieldSet>
                        <FieldLegend>
                            {form.type === 'person'
                                ? 'Rincian perorangan'
                                : 'Rincian organisasi'}
                        </FieldLegend>
                        {form.type === 'person' && (
                            <FieldDescription>
                                Nama lengkap tetap menjadi nama yang
                                ditampilkan. Rincian nama opsional; nama
                                belakang tidak wajib.
                            </FieldDescription>
                        )}
                        {fields(
                            form.type === 'person'
                                ? personProfileFields
                                : organizationProfileFields,
                        )}
                    </FieldSet>
                    <FieldSet>
                        <FieldLegend>Informasi tambahan</FieldLegend>
                        {fields(commonProfileFields)}
                        <Textarea
                            label="Catatan"
                            value={String(form.memo ?? '')}
                            disabled={busy}
                            onChange={(event) =>
                                set('memo', event.target.value)
                            }
                        />
                    </FieldSet>
                    <FieldSet>
                        <FieldLegend>Buku alamat</FieldLegend>
                        <FieldDescription>
                            Kelompok untuk memudahkan pencarian pihak.
                            Pengelompokan ini tidak memberi izin akses.
                        </FieldDescription>
                        {metadata.addressBooks.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada buku alamat. Anda tetap dapat
                                menyimpan pihak tanpa kelompok.
                            </p>
                        ) : (
                            metadata.addressBooks.map((book) => (
                                <Field key={book.id} orientation="horizontal">
                                    <Checkbox
                                        id={`book-${book.id}`}
                                        checked={bookIds.includes(book.id)}
                                        disabled={busy}
                                        onCheckedChange={(checked) =>
                                            setBookIds((ids) =>
                                                checked
                                                    ? [...ids, book.id]
                                                    : ids.filter(
                                                          (id) =>
                                                              id !== book.id,
                                                      ),
                                            )
                                        }
                                    />
                                    <FieldLabel htmlFor={`book-${book.id}`}>
                                        {book.name}
                                    </FieldLabel>
                                </Field>
                            ))
                        )}
                    </FieldSet>
                </form>
                <SheetFooter>
                    <Button variant="outline" disabled={busy} onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" form="party-form" disabled={busy}>
                        {busy ? 'Menyimpan…' : 'Simpan'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

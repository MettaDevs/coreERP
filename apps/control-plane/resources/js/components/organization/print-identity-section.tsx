import { Save, Trash2, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { Textarea } from '@apperp/ui/textarea';
import { apiJson, apiRequest, errorText } from '@/lib/core-api';

type Logo = {
    id: string;
    position: 'kiri' | 'tengah' | 'kanan';
    width_mm: number;
    original_name: string;
    size: number;
};

type Identity = {
    organization_id: string;
    display_name: string;
    display_name_custom: string | null;
    parent_lines: string[];
    address_lines: string[];
    phone: string | null;
    whatsapp: string | null;
    fax: string | null;
    email: string | null;
    website: string | null;
    tax_id: string | null;
    registration_id: string | null;
    footer_text: string | null;
    logos: Logo[];
};

type Form = {
    display_name: string;
    parent_lines: [string, string];
    tax_id: string;
    registration_id: string;
    footer_text: string;
};

const POSITION_LABEL: Record<Logo['position'], string> = {
    kiri: 'Kiri',
    tengah: 'Tengah',
    kanan: 'Kanan',
};

function toForm(identity: Identity | null): Form {
    return {
        display_name: identity?.display_name_custom ?? '',
        parent_lines: [
            identity?.parent_lines[0] ?? '',
            identity?.parent_lines[1] ?? '',
        ],
        tax_id: identity?.tax_id ?? '',
        registration_id: identity?.registration_id ?? '',
        footer_text: identity?.footer_text ?? '',
    };
}

/**
 * Identitas cetak satu organisasi: yang tampil di kop dan footer semua dokumen yang
 * dicetak atas nama organisasi ini. Padanan Company Information Business Central,
 * dengan logo sebagai daftar berposisi supaya kop instansi (lambang daerah kiri, logo
 * instansi kanan) tidak perlu template sendiri.
 *
 * Alamat dan kontak tidak diisi di sini: kop membacanya dari bagian Alamat dan
 * Informasi kontak organisasi (buku alamat party), dan bagian ini hanya menampilkan
 * apa yang akan tercetak. Layout laporan membaca semuanya lewat placeholder `${kop.*}`.
 */
export function PrintIdentitySection({
    organizationId,
    organizationName,
    canManage,
}: {
    organizationId: string;
    organizationName: string;
    canManage: boolean;
}) {
    const base = `/api/v1/organizations/${organizationId}/print-identity`;
    const [identity, setIdentity] = useState<Identity | null>(null);
    const [form, setForm] = useState<Form>(toForm(null));
    const [dirty, setDirty] = useState(false);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [position, setPosition] = useState<Logo['position']>('kiri');
    const fileInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        let cancelled = false;
        apiJson<{ data: Identity }>(base)
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setIdentity(result.data);
                setForm(toForm(result.data));
                setError('');
            })
            .catch((caught: unknown) => {
                if (!cancelled) {
                    setError(
                        errorText(
                            caught,
                            'Identitas cetak belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [base]);

    const update = <K extends keyof Form>(key: K, value: Form[K]) => {
        setForm((current) => ({ ...current, [key]: value }));
        setDirty(true);
    };

    const save = async () => {
        setSaving(true);

        try {
            const result = await apiJson<{ data: Identity }>(base, {
                method: 'PUT',
                body: JSON.stringify(form),
            });
            setIdentity(result.data);
            setForm(toForm(result.data));
            setDirty(false);
            toast.success('Identitas cetak disimpan.');
        } catch (caught) {
            toast.error(
                errorText(caught, 'Identitas cetak belum dapat disimpan.'),
            );
        } finally {
            setSaving(false);
        }
    };

    const uploadLogo = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        const body = new FormData();
        body.append('file', file);
        body.append('position', position);

        try {
            const result = await apiJson<{ data: Identity }>(`${base}/logos`, {
                method: 'POST',
                body,
            });
            setIdentity(result.data);
            toast.success('Logo ditambahkan.');
        } catch (caught) {
            toast.error(errorText(caught, 'Logo belum dapat diunggah.'));
        } finally {
            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    };

    const changeLogo = async (
        logo: Logo,
        patch: Partial<Pick<Logo, 'position' | 'width_mm'>>,
    ) => {
        try {
            const result = await apiJson<{ data: Identity }>(
                `${base}/logos/${logo.id}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify(patch),
                },
            );
            setIdentity(result.data);
        } catch (caught) {
            toast.error(errorText(caught, 'Logo belum dapat diubah.'));
        }
    };

    const removeLogo = async (logo: Logo) => {
        try {
            const response = await apiRequest(`${base}/logos/${logo.id}`, {
                method: 'DELETE',
            });
            const result = (await response.json()) as { data: Identity };
            setIdentity(result.data);
            toast.success('Logo dihapus.');
        } catch (caught) {
            toast.error(errorText(caught, 'Logo belum dapat dihapus.'));
        }
    };

    if (loading) {
        return (
            <p className="text-sm text-muted-foreground">
                Memuat identitas cetak…
            </p>
        );
    }

    const logos = identity?.logos ?? [];
    const text = (key: keyof Form, label: string, placeholder?: string) => (
        <Field key={key}>
            <Input
                label={label}
                placeholder={placeholder}
                value={form[key] as string}
                readOnly={!canManage}
                onChange={(event) =>
                    update(key, event.target.value as Form[typeof key])
                }
            />
        </Field>
    );

    return (
        <div className="space-y-6">
            {error && <p className="text-sm text-destructive">{error}</p>}
            <p className="text-sm text-muted-foreground">
                Yang tampil di kop dan footer setiap dokumen yang dicetak atas
                nama {organizationName}. Layout laporan membacanya lewat
                placeholder <code>{'${kop.*}'}</code>; mengubah di sini berlaku
                untuk semua layout.
            </p>

            <FieldGroup className="grid gap-4 md:grid-cols-2">
                {text('display_name', 'Nama pada kop', organizationName)}
                <Field>
                    <Input
                        label="Baris induk 1"
                        placeholder="PEMERINTAH KABUPATEN BADUNG"
                        value={form.parent_lines[0]}
                        readOnly={!canManage}
                        onChange={(event) =>
                            update('parent_lines', [
                                event.target.value,
                                form.parent_lines[1],
                            ])
                        }
                    />
                    <FieldDescription>
                        Diisi hanya oleh instansi yang kopnya menumpuk nama
                        induk di atas nama sendiri. Perusahaan swasta biasanya
                        kosong.
                    </FieldDescription>
                </Field>
                <Field>
                    <Input
                        label="Baris induk 2"
                        placeholder="DINAS KESEHATAN"
                        value={form.parent_lines[1]}
                        readOnly={!canManage}
                        onChange={(event) =>
                            update('parent_lines', [
                                form.parent_lines[0],
                                event.target.value,
                            ])
                        }
                    />
                </Field>
            </FieldGroup>

            <div className="rounded-lg border bg-muted/30 p-4">
                <p className="text-sm font-medium">
                    Alamat dan kontak yang tercetak
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    Diambil dari alamat utama pada bagian Alamat dan kontak
                    utama tiap jenis pada bagian Informasi kontak. Ubah di sana;
                    di sini hanya ditampilkan.
                </p>
                <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Alamat
                        </dt>
                        <dd className="whitespace-pre-line">
                            {identity?.address_lines.length
                                ? identity.address_lines.join('\n')
                                : 'Belum ada alamat utama'}
                        </dd>
                    </div>
                    <div className="space-y-1">
                        {(
                            [
                                ['Telepon', identity?.phone],
                                ['WhatsApp', identity?.whatsapp],
                                ['Faks', identity?.fax],
                                ['Email', identity?.email],
                                ['Laman', identity?.website],
                            ] as const
                        ).map(([label, value]) => (
                            <div key={label} className="flex gap-2">
                                <dt className="w-20 shrink-0 text-xs leading-5 text-muted-foreground">
                                    {label}
                                </dt>
                                <dd>{value || '—'}</dd>
                            </div>
                        ))}
                    </div>
                </dl>
            </div>

            <FieldGroup className="grid gap-4 md:grid-cols-2">
                {text('tax_id', 'NPWP')}
                {text('registration_id', 'NIB')}
            </FieldGroup>

            <Field>
                <Textarea
                    label="Teks footer"
                    placeholder="Dokumen ini sah tanpa tanda tangan basah."
                    value={form.footer_text}
                    readOnly={!canManage}
                    onChange={(event) =>
                        update('footer_text', event.target.value)
                    }
                />
                <FieldDescription>
                    Muncul di bagian bawah dokumen yang layoutnya memakai
                    placeholder footer.
                </FieldDescription>
            </Field>

            {canManage && (
                <div className="flex justify-end">
                    <Button
                        type="button"
                        size="sm"
                        disabled={!dirty || saving}
                        onClick={() => void save()}
                    >
                        <Save className="mr-1.5 size-3.5" />
                        {saving ? 'Menyimpan…' : 'Simpan identitas cetak'}
                    </Button>
                </div>
            )}

            <div className="space-y-3">
                <FieldLabel>Logo</FieldLabel>
                <FieldDescription>
                    Satu logo swasta biasanya di kiri. Instansi menaruh lambang
                    daerah di kiri dan logo instansi di kanan. Layout bawaan
                    menempatkannya sesuai posisi; layout dengan lebih banyak
                    logo memakai placeholder logo berurutan.
                </FieldDescription>
                {logos.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada logo.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {logos.map((logo, index) => (
                            <li
                                key={logo.id}
                                className="flex flex-wrap items-center gap-3 px-3 py-2"
                            >
                                <img
                                    src={`${base}/logos/${logo.id}`}
                                    alt={logo.original_name}
                                    className="h-12 w-20 rounded border bg-white object-contain p-1"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {logo.original_name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Logo ke-{index + 1} ·{' '}
                                        {(logo.size / 1024).toFixed(0)} KB
                                    </p>
                                </div>
                                {canManage ? (
                                    <>
                                        <div className="w-32">
                                            <NativeSelect
                                                label="Posisi"
                                                value={logo.position}
                                                onChange={(event) =>
                                                    void changeLogo(logo, {
                                                        position: event.target
                                                            .value as Logo['position'],
                                                    })
                                                }
                                            >
                                                {(
                                                    Object.keys(
                                                        POSITION_LABEL,
                                                    ) as Logo['position'][]
                                                ).map((value) => (
                                                    <NativeSelectOption
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {POSITION_LABEL[value]}
                                                    </NativeSelectOption>
                                                ))}
                                            </NativeSelect>
                                        </div>
                                        <div className="w-28">
                                            <Input
                                                label="Lebar (mm)"
                                                type="number"
                                                min={8}
                                                max={80}
                                                defaultValue={logo.width_mm}
                                                onBlur={(event) => {
                                                    const width = Number(
                                                        event.target.value,
                                                    );

                                                    if (
                                                        width >= 8 &&
                                                        width <= 80 &&
                                                        width !== logo.width_mm
                                                    ) {
                                                        void changeLogo(logo, {
                                                            width_mm: width,
                                                        });
                                                    }
                                                }}
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Hapus ${logo.original_name}`}
                                            onClick={() =>
                                                void removeLogo(logo)
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </>
                                ) : (
                                    <Badge variant="outline">
                                        {POSITION_LABEL[logo.position]} ·{' '}
                                        {logo.width_mm} mm
                                    </Badge>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {canManage && logos.length < 4 && (
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="w-full sm:w-40">
                            <NativeSelect
                                label="Posisi logo baru"
                                value={position}
                                onChange={(event) =>
                                    setPosition(
                                        event.target.value as Logo['position'],
                                    )
                                }
                            >
                                {(
                                    Object.keys(
                                        POSITION_LABEL,
                                    ) as Logo['position'][]
                                ).map((value) => (
                                    <NativeSelectOption
                                        key={value}
                                        value={value}
                                    >
                                        {POSITION_LABEL[value]}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                        </div>
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/png,image/jpeg"
                            className="hidden"
                            aria-hidden
                            onChange={(event) =>
                                void uploadLogo(event.target.files?.[0])
                            }
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => fileInput.current?.click()}
                        >
                            <Upload className="mr-1.5 size-3.5" />
                            Unggah logo (PNG/JPG, maks 2 MB)
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}

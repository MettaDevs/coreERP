import { Button } from '@apperp/ui/button';
import { Card, CardHeader, CardTitle } from '@apperp/ui/card';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Textarea } from '@apperp/ui/textarea';
import {
    Building2,
    Info,
    FileText,
    ChevronUp,
    ChevronDown,
} from 'lucide-react';
import { useState } from 'react';

interface OrganizationFormProps {
    data: Record<string, any>;
    partyType: string;
    typeOptions: Array<{ value: string; label: string }>;
    onTypeChange: (type: string | null) => void;
    onChange: (field: string, value: any) => void;
    errors?: Record<string, string>;
}

export function OrganizationForm({
    data,
    partyType,
    typeOptions,
    onTypeChange,
    onChange,
    errors,
}: OrganizationFormProps) {
    const [isExpanded, setIsExpanded] = useState(true);

    return (
        <Card className="overflow-hidden border border-border shadow-xs">
            {/* FASTTAB HEADER UTAMA (1 BUKA-TUTUP UNTUK SELURUH FORM GENERAL) */}
            <CardHeader
                className="flex cursor-pointer flex-row items-center justify-between border-b px-5 py-3.5 transition-colors select-none hover:bg-muted/30"
                onClick={() => setIsExpanded(!isExpanded)}
            >
                <CardTitle className="flex items-center gap-2 text-sm font-semibold text-foreground">
                    <Building2 className="h-4 w-4 text-primary" />
                    General
                </CardTitle>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={(e) => {
                        e.stopPropagation();
                        setIsExpanded(!isExpanded);
                    }}
                    className="h-7 w-7 rounded-sm text-muted-foreground hover:text-foreground"
                >
                    {isExpanded ? (
                        <ChevronUp className="h-4 w-4" />
                    ) : (
                        <ChevronDown className="h-4 w-4" />
                    )}
                </Button>
            </CardHeader>

            {isExpanded && (
                <div className="space-y-6 p-6">
                    {/* 1. IDENTIFIKASI UTAMA */}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Input
                            id="party_id"
                            label="ID Party"
                            value={data.party_id || ''}
                            readOnly
                            className="bg-muted/40 font-mono text-sm font-semibold"
                        />

                        <Select
                            label="Tipe"
                            items={typeOptions}
                            value={partyType}
                            onValueChange={onTypeChange}
                        />

                        <Input
                            id="name"
                            label="Nama Legal Organisasi"
                            required
                            value={data.name || ''}
                            onChange={(e) => onChange('name', e.target.value)}
                            placeholder="Masukkan nama legal organisasi"
                            aria-invalid={!!errors?.name}
                        />

                        <Input
                            id="search_name"
                            label="Nama Pencarian"
                            value={data.search_name || ''}
                            onChange={(e) =>
                                onChange('search_name', e.target.value)
                            }
                            placeholder="Masukkan nama pencarian"
                        />

                        <Input
                            id="payment_priority"
                            label="Prioritas Pembayaran"
                            value={data.payment_priority || ''}
                            onChange={(e) =>
                                onChange('payment_priority', e.target.value)
                            }
                            placeholder="Masukkan prioritas pembayaran"
                        />
                    </div>

                    {/* 2. DETAIL ORGANISASI */}
                    <div className="space-y-4">
                        <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <Building2 className="h-4 w-4 text-primary" />
                            <span>Detail Organisasi</span>
                        </div>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Input
                                id="organization_number"
                                label="Nomor Registrasi / NPWP"
                                value={data.organization_number || ''}
                                onChange={(e) =>
                                    onChange(
                                        'organization_number',
                                        e.target.value,
                                    )
                                }
                                placeholder="Masukkan nomor registrasi atau NPWP"
                            />

                            <Input
                                id="number_of_employees"
                                label="Jumlah Karyawan"
                                type="number"
                                min={0}
                                value={data.number_of_employees ?? 0}
                                onChange={(e) =>
                                    onChange(
                                        'number_of_employees',
                                        e.target.value
                                            ? parseInt(e.target.value, 10)
                                            : 0,
                                    )
                                }
                            />

                            <Select
                                label="Klasifikasi ABC"
                                items={[]}
                                value={data.abc_code ?? null}
                                onValueChange={(val) =>
                                    onChange('abc_code', val)
                                }
                                placeholder="Pilih klasifikasi ABC"
                            />

                            <Input
                                id="duns_number"
                                label="Nomor DUNS"
                                value={data.duns_number || ''}
                                onChange={(e) =>
                                    onChange('duns_number', e.target.value)
                                }
                                placeholder="Masukkan nomor DUNS"
                            />
                        </div>
                    </div>

                    {/* 3. INFORMASI LAINNYA */}
                    <div className="space-y-4">
                        <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <Info className="h-4 w-4 text-primary" />
                            <span>Informasi Lainnya</span>
                        </div>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Select
                                label="Buku Alamat"
                                items={[]}
                                value={data.address_books ?? null}
                                onValueChange={(val) =>
                                    onChange('address_books', val)
                                }
                                placeholder="Pilih buku alamat"
                            />

                            <Input
                                id="known_as"
                                label="Nama Panggilan / Alias"
                                value={data.known_as || ''}
                                onChange={(e) =>
                                    onChange('known_as', e.target.value)
                                }
                                placeholder="Masukkan nama panggilan atau alias"
                            />

                            <Input
                                id="phonetic_name"
                                label="Nama Fonetik"
                                value={data.phonetic_name || ''}
                                onChange={(e) =>
                                    onChange('phonetic_name', e.target.value)
                                }
                                placeholder="Masukkan nama fonetik"
                            />

                            <Select
                                label="Bahasa"
                                items={[]}
                                value={data.language ?? null}
                                onValueChange={(val) =>
                                    onChange('language', val)
                                }
                                placeholder="Pilih bahasa"
                            />
                        </div>
                    </div>

                    {/* 4. MEMO */}
                    <div className="space-y-3">
                        <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <FileText className="h-4 w-4 text-primary" />
                            <span>Memo</span>
                        </div>
                        <Textarea
                            id="memo"
                            rows={4}
                            value={data.memo || ''}
                            onChange={(e) => onChange('memo', e.target.value)}
                            placeholder="Tulis catatan atau memo internal untuk organisasi ini..."
                            className="w-full resize-y text-sm"
                        />
                    </div>
                </div>
            )}
        </Card>
    );
}

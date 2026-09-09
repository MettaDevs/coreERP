import { useState } from 'react';
import {
    UserCheck,
    Info,
    FileText,
    ChevronUp,
    ChevronDown,
} from 'lucide-react';
import { Card, CardHeader, CardTitle } from '@apperp/ui/card';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Textarea } from '@apperp/ui/textarea';

interface PersonFormProps {
    data: Record<string, any>;
    partyType: string;
    typeOptions: Array<{ value: string; label: string }>;
    onTypeChange: (type: string | null) => void;
    onChange: (field: string, value: any) => void;
    errors?: Record<string, string>;
}

export function PersonForm({
    data,
    partyType,
    typeOptions,
    onTypeChange,
    onChange,
    errors,
}: PersonFormProps) {
    const [isExpanded, setIsExpanded] = useState(true);

    return (
        <Card className="overflow-hidden border border-border shadow-xs">
            {/* FASTTAB HEADER UTAMA (1 BUKA-TUTUP UNTUK SELURUH FORM GENERAL) */}
            <CardHeader
                className="flex cursor-pointer flex-row items-center justify-between border-b px-5 py-3.5 transition-colors select-none hover:bg-muted/30"
                onClick={() => setIsExpanded(!isExpanded)}
            >
                <CardTitle className="flex items-center gap-2 text-sm font-semibold text-foreground">
                    <UserCheck className="h-4 w-4 text-primary" />
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
                            label="ID Pihak"
                            value={data.party_id || ''}
                            readOnly
                            className="bg-muted/40 font-mono text-sm font-semibold"
                        />

                        <Select
                            label="Tipe Pihak"
                            items={typeOptions}
                            value={partyType}
                            onValueChange={onTypeChange}
                        />

                        <Select
                            label="Gelar Depan"
                            items={[]}
                            value={data.personal_title ?? null}
                            onValueChange={(val) =>
                                onChange('personal_title', val)
                            }
                            placeholder="Pilih gelar depan"
                        />

                        <Input
                            id="first_name"
                            label="Nama Depan"
                            required
                            value={data.first_name || ''}
                            onChange={(e) =>
                                onChange('first_name', e.target.value)
                            }
                            placeholder="Nama depan"
                            aria-invalid={!!errors?.first_name}
                        />

                        <Input
                            id="middle_name"
                            label="Nama Tengah"
                            value={data.middle_name || ''}
                            onChange={(e) =>
                                onChange('middle_name', e.target.value)
                            }
                            placeholder="Nama tengah (opsional)"
                        />

                        <Input
                            id="last_name"
                            label="Nama Belakang"
                            required
                            value={data.last_name || ''}
                            onChange={(e) =>
                                onChange('last_name', e.target.value)
                            }
                            placeholder="Nama belakang"
                            aria-invalid={!!errors?.last_name}
                        />

                        <Input
                            id="known_as"
                            label="Nama Panggilan"
                            value={data.known_as || ''}
                            onChange={(e) =>
                                onChange('known_as', e.target.value)
                            }
                            placeholder="Nama panggilan"
                        />

                        <Input
                            id="initials"
                            label="Inisial"
                            value={data.initials || ''}
                            onChange={(e) =>
                                onChange('initials', e.target.value)
                            }
                            placeholder="Inisial"
                        />

                        <Input
                            id="search_name"
                            label="Nama Pencarian"
                            value={data.search_name || ''}
                            onChange={(e) =>
                                onChange('search_name', e.target.value)
                            }
                            placeholder="Nama pencarian"
                        />

                        <Input
                            id="last_name_prefix"
                            label="Awalan Nama Belakang"
                            value={data.last_name_prefix || ''}
                            onChange={(e) =>
                                onChange('last_name_prefix', e.target.value)
                            }
                            placeholder="Awalan nama belakang"
                        />

                        <Select
                            label="Gelar Belakang / Akhiran Nama"
                            items={[]}
                            value={data.personal_suffix ?? null}
                            onValueChange={(val) =>
                                onChange('personal_suffix', val)
                            }
                            placeholder="Pilih gelar belakang"
                        />

                        <Select
                            label="Format Tampilan Nama"
                            items={[]}
                            value={data.display_as ?? null}
                            onValueChange={(val) => onChange('display_as', val)}
                            placeholder="Pilih format tampilan"
                        />
                    </div>

                    {/* 2. DETAIL PERORANGAN */}
                    <div className="space-y-4">
                        <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <UserCheck className="h-4 w-4 text-primary" />
                            <span>Detail Perorangan</span>
                        </div>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Select
                                label="Jenis Kelamin"
                                items={[]}
                                value={data.gender ?? null}
                                onValueChange={(val) => onChange('gender', val)}
                                placeholder="Pilih jenis kelamin"
                            />

                            <Select
                                label="Status Pernikahan"
                                items={[]}
                                value={data.marital_status ?? null}
                                onValueChange={(val) =>
                                    onChange('marital_status', val)
                                }
                                placeholder="Pilih status pernikahan"
                            />

                            <Input
                                id="birthday"
                                label="Tanggal Lahir"
                                type="date"
                                value={data.birthday || ''}
                                onChange={(e) =>
                                    onChange('birthday', e.target.value)
                                }
                            />

                            <Input
                                id="anniversary"
                                label="Ulang Tahun Pernikahan"
                                type="date"
                                value={data.anniversary || ''}
                                onChange={(e) =>
                                    onChange('anniversary', e.target.value)
                                }
                            />

                            <Input
                                id="children"
                                label="Jumlah Anak"
                                type="number"
                                min={0}
                                value={data.children || ''}
                                onChange={(e) =>
                                    onChange('children', e.target.value)
                                }
                                placeholder="0"
                            />

                            <Input
                                id="hobbies"
                                label="Hobi / Minat"
                                value={data.hobbies || ''}
                                onChange={(e) =>
                                    onChange('hobbies', e.target.value)
                                }
                                placeholder="Hobi atau minat"
                            />

                            <Input
                                id="professional_title"
                                label="Gelar Profesi"
                                value={data.professional_title || ''}
                                onChange={(e) =>
                                    onChange(
                                        'professional_title',
                                        e.target.value,
                                    )
                                }
                                placeholder="Gelar profesi"
                            />

                            <Input
                                id="professional_suffix"
                                label="Sertifikasi / Gelar Tambahan"
                                value={data.professional_suffix || ''}
                                onChange={(e) =>
                                    onChange(
                                        'professional_suffix',
                                        e.target.value,
                                    )
                                }
                                placeholder="Sertifikasi / gelar tambahan"
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
                                id="payment_priority"
                                label="Prioritas Pembayaran"
                                value={data.payment_priority || ''}
                                onChange={(e) =>
                                    onChange('payment_priority', e.target.value)
                                }
                                placeholder="Prioritas pembayaran"
                            />

                            <Input
                                id="phonetic_first"
                                label="Nama Depan Fonetik"
                                value={data.phonetic_first || ''}
                                onChange={(e) =>
                                    onChange('phonetic_first', e.target.value)
                                }
                                placeholder="Fonetik nama depan"
                            />

                            <Input
                                id="phonetic_middle"
                                label="Nama Tengah Fonetik"
                                value={data.phonetic_middle || ''}
                                onChange={(e) =>
                                    onChange('phonetic_middle', e.target.value)
                                }
                                placeholder="Fonetik nama tengah"
                            />

                            <Input
                                id="phonetic_last"
                                label="Nama Belakang Fonetik"
                                value={data.phonetic_last || ''}
                                onChange={(e) =>
                                    onChange('phonetic_last', e.target.value)
                                }
                                placeholder="Fonetik nama belakang"
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
                            placeholder="Tulis catatan atau memo internal untuk perorangan ini..."
                            className="w-full resize-y text-sm"
                        />
                    </div>
                </div>
            )}
        </Card>
    );
}

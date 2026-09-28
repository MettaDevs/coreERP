import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import type { BreadcrumbItem } from '@/types/navigation';

type Currency = {
    code: string;
    name: string;
    amount_decimals: number;
    unit_amount_decimals: number;
    is_default: boolean;
};
type Props = {
    canManage: boolean;
    limits: { amount_decimals: number; unit_amount_decimals: number };
    currencies: Currency[];
};

const CONTOH = 1234567.891;

function contoh(decimals: number): string {
    return new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(CONTOH);
}

function range(max: number): number[] {
    return Array.from({ length: max + 1 }, (_, index) => index);
}

function EditPrecisionDialog({
    currency,
    limits,
    onClose,
}: {
    currency: Currency;
    limits: Props['limits'];
    onClose: () => void;
}) {
    const form = useForm({
        amount_decimals: String(currency.amount_decimals),
        unit_amount_decimals: String(currency.unit_amount_decimals),
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Presisi {currency.code}</DialogTitle>
                    <DialogDescription>
                        Berlaku untuk posting yang terbit sesudah disimpan.
                        Posting yang sudah terkirim tidak berubah. Pastikan
                        aplikasi finance menyimpan nilai dengan desimal yang
                        sama atau lebih banyak.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/settings/currencies/${currency.code}`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogBody className="grid gap-4 py-3 md:grid-cols-2">
                        <Field
                            data-invalid={Boolean(form.errors.amount_decimals)}
                        >
                            <NativeSelect
                                label="Presisi nilai"
                                value={form.data.amount_decimals}
                                onChange={(event) =>
                                    form.setData(
                                        'amount_decimals',
                                        event.target.value,
                                    )
                                }
                            >
                                {range(limits.amount_decimals).map((n) => (
                                    <NativeSelectOption key={n} value={n}>
                                        {n} desimal
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                            <FieldDescription>
                                Dipakai untuk baris jurnal dan total. Contoh:{' '}
                                {contoh(Number(form.data.amount_decimals))}
                            </FieldDescription>
                            <FieldError>
                                {form.errors.amount_decimals}
                            </FieldError>
                        </Field>
                        <Field
                            data-invalid={Boolean(
                                form.errors.unit_amount_decimals,
                            )}
                        >
                            <NativeSelect
                                label="Presisi harga satuan"
                                value={form.data.unit_amount_decimals}
                                onChange={(event) =>
                                    form.setData(
                                        'unit_amount_decimals',
                                        event.target.value,
                                    )
                                }
                            >
                                {range(limits.unit_amount_decimals).map((n) => (
                                    <NativeSelectOption key={n} value={n}>
                                        {n} desimal
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                            <FieldDescription>
                                Hanya untuk harga per unit di rincian, tidak
                                pernah di baris jurnal. Contoh:{' '}
                                {contoh(Number(form.data.unit_amount_decimals))}
                            </FieldDescription>
                            <FieldError>
                                {form.errors.unit_amount_decimals}
                            </FieldError>
                        </Field>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction type="submit" disabled={form.processing}>
                            Simpan presisi
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Currencies({ canManage, limits, currencies }: Props) {
    const [editing, setEditing] = useState<Currency | null>(null);

    return (
        <>
            <Head title="Mata uang" />
            <main className="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Mata uang"
                    description="Jumlah desimal yang dipakai saat nilai dikirim ke aplikasi finance."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Presisi mata uang</CardTitle>
                        <CardDescription>
                            Nilai setiap baris jurnal dibulatkan ke presisi
                            nilai sebelum dikirim, satu kali, sehingga jurnal
                            selalu seimbang di aplikasi finance. Untuk saat ini
                            hanya rupiah yang dipakai.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Kode</TableHead>
                                        <TableHead>Nama</TableHead>
                                        <TableHead>Presisi nilai</TableHead>
                                        <TableHead>
                                            Presisi harga satuan
                                        </TableHead>
                                        <TableHead>Contoh nilai</TableHead>
                                        {canManage && (
                                            <TableHead className="w-24 text-right">
                                                Aksi
                                            </TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {currencies.map((currency) => (
                                        <TableRow key={currency.code}>
                                            <TableCell className="font-mono font-medium">
                                                {currency.code}
                                            </TableCell>
                                            <TableCell>
                                                <span>{currency.name}</span>
                                                {currency.is_default && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2"
                                                    >
                                                        Bawaan
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {currency.amount_decimals}{' '}
                                                desimal
                                            </TableCell>
                                            <TableCell>
                                                {currency.unit_amount_decimals}{' '}
                                                desimal
                                            </TableCell>
                                            <TableCell className="font-mono text-muted-foreground">
                                                {contoh(
                                                    currency.amount_decimals,
                                                )}
                                            </TableCell>
                                            {canManage && (
                                                <TableCell className="text-right">
                                                    <ActionButton
                                                        action="edit"
                                                        type="button"
                                                        size="sm"
                                                        onClick={() =>
                                                            setEditing(currency)
                                                        }
                                                    >
                                                        Ubah
                                                    </ActionButton>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </main>
            {editing && (
                <EditPrecisionDialog
                    key={editing.code}
                    currency={editing}
                    limits={limits}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

Currencies.layout = {
    breadcrumbs: [
        { title: 'Mata uang', href: '/settings/currencies' },
    ] satisfies BreadcrumbItem[],
};

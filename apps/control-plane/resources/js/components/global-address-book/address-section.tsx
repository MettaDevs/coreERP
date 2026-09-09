import { useState } from 'react';
import { ChevronUp, ChevronDown, MapPin } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { ActionButton } from '@apperp/ui/action-button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import type { AddressItem } from '@/types/global-address-book';
import { AddressSheetForm } from './address-sheet-form';

interface AddressSectionProps {
    addresses: AddressItem[];
    onChange: (addresses: AddressItem[]) => void;
}

export function AddressSection({ addresses, onChange }: AddressSectionProps) {
    const [isExpanded, setIsExpanded] = useState(true);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [selectedAddressId, setSelectedAddressId] = useState<string | null>(
        null,
    );
    const [editingAddress, setEditingAddress] = useState<AddressItem | null>(
        null,
    );

    const selectedAddress =
        addresses.find((a) => a.id === selectedAddressId) || null;

    const handleOpenCreate = () => {
        setEditingAddress(null);
        setSheetOpen(true);
    };

    const handleOpenEdit = () => {
        if (!selectedAddress) return;
        setEditingAddress(selectedAddress);
        setSheetOpen(true);
    };

    const handleDelete = () => {
        if (!selectedAddressId) return;
        onChange(addresses.filter((a) => a.id !== selectedAddressId));
        setSelectedAddressId(null);
    };

    const handleSave = (savedAddress: AddressItem) => {
        if (editingAddress) {
            let updated = addresses.map((a) =>
                a.id === savedAddress.id ? savedAddress : a,
            );
            if (savedAddress.is_primary) {
                updated = updated.map((a) =>
                    a.id === savedAddress.id ? a : { ...a, is_primary: false },
                );
            }
            onChange(updated);
        } else {
            let updated = [...addresses];
            if (savedAddress.is_primary) {
                updated = updated.map((a) => ({ ...a, is_primary: false }));
            }
            onChange([...updated, savedAddress]);
        }
    };

    return (
        <>
            <Card className="overflow-hidden border border-border shadow-xs">
                {/* 1. FASTTAB HEADER */}
                <CardHeader
                    className="flex cursor-pointer flex-row items-center justify-between border-b px-5 py-3.5 transition-colors select-none hover:bg-muted/30"
                    onClick={() => setIsExpanded(!isExpanded)}
                >
                    <CardTitle className="flex items-center gap-2 text-sm font-semibold text-foreground">
                        <MapPin className="h-4 w-4 text-primary" />
                        Alamat (Addresses)
                    </CardTitle>
                    <div className="flex items-center gap-2">
                        {isExpanded && (
                            <div
                                className="mr-1 flex flex-wrap items-center gap-2"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <ActionButton
                                    action="create"
                                    size="sm"
                                    onClick={handleOpenCreate}
                                >
                                    Tambah Alamat
                                </ActionButton>

                                <ActionButton
                                    action="edit"
                                    size="sm"
                                    disabled={!selectedAddress}
                                    onClick={handleOpenEdit}
                                >
                                    Ubah
                                </ActionButton>

                                <ActionButton
                                    action="archive"
                                    size="sm"
                                    disabled={!selectedAddressId}
                                    onClick={handleDelete}
                                >
                                    Hapus / Arsipkan
                                </ActionButton>
                            </div>
                        )}
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
                    </div>
                </CardHeader>

                {isExpanded && (
                    <CardContent className="pt-4">
                        {addresses.length === 0 ? (
                            <Empty className="py-8">
                                <EmptyMedia variant="icon">
                                    <MapPin className="h-6 w-6" />
                                </EmptyMedia>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Belum ada alamat terdaftar
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Tambahkan alamat kantor, pabrik, gudang,
                                        atau lokasi pengiriman pihak ini.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/40">
                                            <TableHead className="w-12 text-center">
                                                #
                                            </TableHead>
                                            <TableHead className="w-48">
                                                Nama / Keterangan
                                            </TableHead>
                                            <TableHead>
                                                Alamat Lengkap
                                            </TableHead>
                                            <TableHead className="w-40">
                                                Peruntukan
                                            </TableHead>
                                            <TableHead className="w-24 text-center">
                                                Utama
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {addresses.map((addr, idx) => {
                                            const isSelected =
                                                selectedAddressId === addr.id;
                                            return (
                                                <TableRow
                                                    key={addr.id || idx}
                                                    onClick={() =>
                                                        setSelectedAddressId(
                                                            addr.id,
                                                        )
                                                    }
                                                    className={`cursor-pointer transition-colors ${
                                                        isSelected
                                                            ? 'bg-primary/10 font-medium'
                                                            : 'hover:bg-muted/30'
                                                    }`}
                                                >
                                                    <TableCell className="text-center font-mono text-xs text-muted-foreground">
                                                        {idx + 1}
                                                    </TableCell>
                                                    <TableCell className="py-2.5 text-xs font-semibold">
                                                        {addr.description ||
                                                            '-'}
                                                    </TableCell>
                                                    <TableCell className="py-2.5 text-xs">
                                                        <span className="font-medium">
                                                            {addr.street || '-'}
                                                        </span>
                                                        <span className="block text-[11px] text-muted-foreground">
                                                            {[
                                                                addr.building_complement,
                                                                addr.district,
                                                                addr.city,
                                                                addr.state,
                                                                addr.postal_code,
                                                                addr.country,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(', ')}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell className="py-2.5 text-xs">
                                                        {addr.purpose || '-'}
                                                    </TableCell>
                                                    <TableCell className="py-2.5 text-center">
                                                        {addr.is_primary ? (
                                                            <Badge
                                                                variant="default"
                                                                className="bg-emerald-600 text-[10px]"
                                                            >
                                                                Utama
                                                            </Badge>
                                                        ) : addr.is_private ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-[10px] text-muted-foreground"
                                                            >
                                                                Pribadi
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                -
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                )}
            </Card>

            <AddressSheetForm
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                initialData={editingAddress}
                onSave={handleSave}
            />
        </>
    );
}

import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Users2 } from 'lucide-react';
import { useState, useRef } from 'react';
import type { RelationshipItem } from '@/types/global-address-book';

interface RelationshipDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    currentPartyId: string;
    currentPartyName: string;
    onSave: (rel: RelationshipItem) => void;
}

const RELATIONSHIP_PAIRS = [
    { value: 'pic', labelAtoB: 'PIC dari', labelBtoA: 'Memiliki PIC' },
    {
        value: 'subsidiary',
        labelAtoB: 'Anak perusahaan dari',
        labelBtoA: 'Induk perusahaan dari',
    },
    { value: 'branch', labelAtoB: 'Cabang dari', labelBtoA: 'Memiliki cabang' },
    {
        value: 'contact',
        labelAtoB: 'Kontak person dari',
        labelBtoA: 'Memiliki kontak person',
    },
    {
        value: 'vendor_customer',
        labelAtoB: 'Pemasok / Vendor dari',
        labelBtoA: 'Pelanggan / Klien dari',
    },
];

export function RelationshipDialog({
    open,
    onOpenChange,
    currentPartyId,
    currentPartyName,
    onSave,
}: RelationshipDialogProps) {
    const dialogContentRef = useRef<HTMLDivElement>(null);
    const [selectedPair, setSelectedPair] = useState('');
    const [partyBId, setPartyBId] = useState('');
    const [partyBName, setPartyBName] = useState('');
    const [effectiveDate, setEffectiveDate] = useState(
        new Date().toISOString().slice(0, 10),
    );
    const [expirationDate, setExpirationDate] = useState('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const pair = RELATIONSHIP_PAIRS.find((p) => p.value === selectedPair);

        const newRel: RelationshipItem = {
            id: `REL-${Date.now().toString().slice(-4)}`,
            party_a_id: currentPartyId,
            party_a_name: currentPartyName || '',
            relationship_a_to_b: pair?.labelAtoB || selectedPair,
            relationship_b_to_a: pair?.labelBtoA || selectedPair,
            party_b_id: partyBId,
            party_b_name: partyBName,
            effective_date: effectiveDate,
            expiration_date: expirationDate || undefined,
            status: 'active',
        };

        onSave(newRel);
        onOpenChange(false);
        setPartyBId('');
        setPartyBName('');
        setExpirationDate('');
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                ref={dialogContentRef}
                className="flex flex-col overflow-hidden p-0 sm:max-w-lg"
            >
                <DialogHeader className="border-b px-6 py-4">
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            <Users2 className="size-5" />
                        </div>
                        <div>
                            <DialogTitle>Tambah Hubungan Relasi</DialogTitle>
                            <DialogDescription>
                                Daftarkan hubungan timbal balik antara pihak ini
                                dengan pihak lain.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex flex-1 flex-col overflow-hidden"
                >
                    <div className="space-y-4 overflow-y-auto px-6 py-4">
                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                id="rel_party_a_id"
                                label="Pihak A (ID)"
                                value={currentPartyId}
                                readOnly
                                className="bg-muted/40 font-mono text-xs"
                            />
                            <Input
                                id="rel_party_a_name"
                                label="Pihak A (Nama)"
                                value={currentPartyName || ''}
                                readOnly
                                className="bg-muted/40 text-xs"
                            />
                        </div>

                        <Select
                            label="Jenis Hubungan (A ke B)"
                            items={[]}
                            value={selectedPair}
                            onValueChange={(val) => setSelectedPair(val || '')}
                            placeholder="Pilih jenis hubungan"
                            portalContainer={dialogContentRef}
                        />

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                id="rel_party_b_id"
                                label="Pihak B (ID)"
                                placeholder="ID Pihak B"
                                value={partyBId}
                                onChange={(e) => setPartyBId(e.target.value)}
                            />
                            <Input
                                id="rel_party_b_name"
                                label="Pihak B (Nama Pihak)"
                                placeholder="Nama Pihak B"
                                required
                                value={partyBName}
                                onChange={(e) => setPartyBName(e.target.value)}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                id="rel_effective"
                                label="Berlaku Sejak"
                                type="date"
                                required
                                value={effectiveDate}
                                onChange={(e) =>
                                    setEffectiveDate(e.target.value)
                                }
                            />
                            <Input
                                id="rel_expiration"
                                label="Berakhir Pada"
                                type="date"
                                value={expirationDate}
                                onChange={(e) =>
                                    setExpirationDate(e.target.value)
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter className="border-t bg-background px-6 py-3.5">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Batal
                        </Button>
                        <Button type="submit">Simpan Hubungan</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

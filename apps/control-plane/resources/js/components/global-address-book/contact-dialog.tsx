import { useState, useRef } from 'react';
import { PhoneCall } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@apperp/ui/dialog';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import type { ContactItem, ContactType } from '@/types/global-address-book';

interface ContactDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSave: (contact: ContactItem) => void;
}

export function ContactDialog({ open, onOpenChange, onSave }: ContactDialogProps) {
    const dialogContentRef = useRef<HTMLDivElement>(null);
    const [type, setType] = useState<ContactType>('phone');
    const [value, setValue] = useState('');
    const [description, setDescription] = useState('');
    const [extension, setExtension] = useState('');
    const [isPrimary, setIsPrimary] = useState(false);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const newContact: ContactItem = {
            id: `CNT-${Date.now().toString().slice(-4)}`,
            type,
            value,
            description: description || undefined,
            extension: extension || undefined,
            is_primary: isPrimary,
        };
        onSave(newContact);
        onOpenChange(false);
        setValue('');
        setDescription('');
        setExtension('');
        setIsPrimary(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent ref={dialogContentRef} className="sm:max-w-md flex flex-col overflow-hidden p-0">
                <DialogHeader className="border-b px-6 py-4">
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            <PhoneCall className="size-5" />
                        </div>
                        <div>
                            <DialogTitle>Tambah Informasi Kontak</DialogTitle>
                            <DialogDescription>
                                Daftarkan nomor telepon, email, atau saluran komunikasi lainnya.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="flex flex-1 flex-col overflow-hidden">
                    <div className="space-y-4 px-6 py-4 overflow-y-auto">
                        <Select
                            label="Tipe Kontak"
                            items={[]}
                            value={type}
                            onValueChange={(val) => setType((val as ContactType) || '')}
                            placeholder="Pilih tipe kontak"
                            portalContainer={dialogContentRef}
                        />

                        <Input
                            id="cnt_value"
                            label="Nomor / Alamat Kontak"
                            required
                            placeholder="Masukkan nomor telepon, email, atau tautan"
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                        />

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                id="cnt_desc"
                                label="Keterangan"
                                placeholder="Keterangan kontak"
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                            />
                            <Input
                                id="cnt_ext"
                                label="Ekstensi (Ext)"
                                placeholder="Nomor ekstensi"
                                value={extension}
                                onChange={(e) => setExtension(e.target.value)}
                            />
                        </div>

                        <div className="flex items-center justify-between rounded-lg border p-3 bg-muted/20">
                            <div>
                                <p className="text-sm font-medium text-foreground">Kontak Utama (Primary)</p>
                                <p className="text-xs text-muted-foreground">Jadikan saluran komunikasi ini sebagai kontak utama</p>
                            </div>
                            <Switch
                                checked={isPrimary}
                                onCheckedChange={setIsPrimary}
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
                        <Button type="submit">
                            Simpan Kontak
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}


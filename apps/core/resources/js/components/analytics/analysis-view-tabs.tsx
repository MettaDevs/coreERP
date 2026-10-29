import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@apperp/ui/dropdown-menu';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { ChevronDown, Plus } from 'lucide-react';
import { useId, useLayoutEffect, useRef, useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
/** Tab tampilan bersama; aksinya hanya mengubah konfigurasi analisis milik pengguna. */
export function AnalysisViewTabs({
    names,
    active,
    onSelect,
    onAdd,
    onRename,
    onDuplicate,
    onRemove,
}: {
    names: string[];
    active: string;
    onSelect: (name: string) => void;
    onAdd: () => void;
    onRename: (name: string, next: string) => boolean;
    onDuplicate: (name: string) => void;
    onRemove: (name: string) => void;
}) {
    const tabsRef = useRef<HTMLDivElement>(null);
    const namesKey = JSON.stringify(names);
    useLayoutEffect(() => {
        const root = tabsRef.current;

        if (!root) {
            return;
        }

        const reveal = () =>
            root
                .querySelector<HTMLElement>('[role=tab][data-state=active]')
                ?.parentElement?.scrollIntoView({
                    block: 'nearest',
                    inline: 'nearest',
                });
        reveal();
        const observer = new ResizeObserver(reveal);
        observer.observe(root);

        return () => observer.disconnect();
    }, [active, namesKey]);
    const [editing, setEditing] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [renameErrors, setRenameErrors] = useState<Record<string, string>>(
        {},
    );
    const renameErrorId = useId();

    return (
        <div className="flex min-w-0 flex-col gap-2 py-2">
            <div className="flex min-w-0 items-center gap-2">
                <Tabs
                    ref={tabsRef}
                    value={active}
                    onValueChange={onSelect}
                    className="min-w-0 overflow-x-auto"
                >
                    <TabsList variant="line">
                        {names.map((value) => (
                            <div key={value} className="flex items-center">
                                <TabsTrigger
                                    value={value}
                                    className="max-w-56 truncate"
                                    title={value}
                                >
                                    {value}
                                </TabsTrigger>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon-xs"
                                            aria-label={`Menu tampilan ${value}`}
                                        >
                                            <ChevronDown />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent>
                                        <DropdownMenuItem
                                            onClick={() => {
                                                setEditing(value);
                                                setName(value);
                                                setRenameErrors({});
                                            }}
                                        >
                                            Ubah nama
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            disabled={names.length >= 20}
                                            onClick={() => onDuplicate(value)}
                                        >
                                            Duplikat
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            disabled={names.length === 1}
                                            onClick={() => onRemove(value)}
                                        >
                                            Hapus tampilan
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        ))}
                    </TabsList>
                </Tabs>
                <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label="Tambah tampilan"
                    title={
                        names.length >= 20
                            ? 'Maksimal 20 tampilan'
                            : 'Tambah tampilan'
                    }
                    disabled={names.length >= 20}
                    onClick={onAdd}
                >
                    <Plus />
                </Button>
            </div>
            {editing && (
                <form
                    noValidate
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();

                        const next = name.trim();

                        if (
                            !next ||
                            next.length > 80 ||
                            names.some(
                                (value) => value === next && value !== editing,
                            )
                        ) {
                            setRenameErrors({
                                name: 'Isi nama tampilan yang belum dipakai, paling banyak 80 karakter.',
                            });

                            return;
                        }

                        if (onRename(editing, name.trim())) {
                            setRenameErrors({});
                            setEditing(null);
                        } else {
                            setRenameErrors({
                                name: 'Nama tampilan belum dapat diganti. Periksa nama dan coba simpan lagi.',
                            });
                        }
                    }}
                >
                    <FormErrorHeader
                        errors={renameErrors}
                        operation="Nama tampilan belum disimpan"
                    />
                    <FormControl
                        error={
                            renameErrors.name ? (
                                <span id={renameErrorId}>
                                    {renameErrors.name}
                                </span>
                            ) : undefined
                        }
                        data-error-field="name"
                    >
                        <Input
                            autoFocus
                            name="name"
                            label="Nama tampilan"
                            required
                            aria-invalid={!!renameErrors.name}
                            aria-describedby={
                                renameErrors.name ? renameErrorId : undefined
                            }
                            className="w-64"
                            value={name}
                            maxLength={80}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </FormControl>
                    <ActionButton action="save" type="submit" />
                    <ActionButton
                        action="cancel"
                        type="button"
                        onClick={() => setEditing(null)}
                    />
                </form>
            )}
        </div>
    );
}

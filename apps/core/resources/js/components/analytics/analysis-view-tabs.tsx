import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@apperp/ui/dropdown-menu';
import { Input } from '@apperp/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { ChevronDown, Plus } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
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
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (onRename(editing, name.trim())) {
                            setEditing(null);
                        }
                    }}
                >
                    <Input
                        autoFocus
                        aria-label="Nama tampilan"
                        className="w-64"
                        value={name}
                        maxLength={80}
                        onChange={(event) => setName(event.target.value)}
                    />
                    <ActionButton
                        action="save"
                        type="submit"
                        disabled={!name.trim()}
                    />
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

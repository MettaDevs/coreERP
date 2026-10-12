import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@apperp/ui/command';
import { router, usePage } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import { useShellNavigation } from '@/hooks/use-shell-navigation';

type AppCommandPaletteProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function AppCommandPalette({
    open,
    onOpenChange,
}: AppCommandPaletteProps) {
    const { props } = usePage();
    const { coreItems } = useShellNavigation();
    const seenCoreLinks = new Set<string>();
    const groups = [
        {
            id: 'core',
            name: 'Halaman umum',
            items: coreItems
                .flatMap((rail) =>
                    rail.children.map((item) => ({
                        ...item,
                        section: rail.label,
                    })),
                )
                .filter((item) => {
                    if (seenCoreLinks.has(item.href)) {
                        return false;
                    }

                    seenCoreLinks.add(item.href);

                    return true;
                }),
        },
        ...props.launchableProducts.map((product) => ({
            id: product.id,
            name: product.name,
            items: product.navigation.flatMap((rail) =>
                rail.items.map((item) => ({
                    ...item,
                    icon: Boxes,
                    section: rail.label,
                })),
            ),
        })),
        {
            id: 'applications',
            name: 'Aplikasi',
            items: props.launchableProducts.map((product) => ({
                label: product.name,
                href: product.href,
                icon: Boxes,
                section: 'Buka aplikasi',
            })),
        },
    ];

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Cari halaman atau aplikasi"
            description="Temukan halaman dan aplikasi yang dapat Anda buka."
            showCloseButton={false}
        >
            <CommandInput
                aria-label="Cari halaman atau aplikasi"
                placeholder="Cari halaman atau aplikasi…"
            />
            <CommandList>
                <CommandEmpty>
                    Tidak ada halaman atau aplikasi yang cocok.
                </CommandEmpty>
                {groups
                    .filter((group) => group.items.length > 0)
                    .map((group) => (
                        <CommandGroup key={group.id} heading={group.name}>
                            {group.items.map((item) => (
                                <CommandItem
                                    key={item.href}
                                    value={`${group.id}:${item.href}`}
                                    keywords={[
                                        item.label,
                                        item.section,
                                        group.name,
                                    ]}
                                    onSelect={() => {
                                        onOpenChange(false);
                                        router.visit(item.href);
                                    }}
                                >
                                    {item.icon && <item.icon />}
                                    <span className="min-w-0 flex-1 truncate">
                                        {item.label}
                                    </span>
                                    <span className="max-w-40 truncate text-xs text-muted-foreground">
                                        {item.section}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    ))}
            </CommandList>
        </CommandDialog>
    );
}

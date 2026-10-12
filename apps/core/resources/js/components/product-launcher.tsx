import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@apperp/ui/popover';
import { ScrollArea } from '@apperp/ui/scroll-area';
import { Link, usePage } from '@inertiajs/react';
import { Boxes, Building2, Grid3X3, PackageSearch } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';

import type { EntitledProduct } from '@/types/auth';

const productIcons: Record<string, LucideIcon> = {
    'management-aset': PackageSearch,
};

function ProductLink({
    product,
    icon: Icon,
    launchable,
    onLaunch,
}: {
    product: EntitledProduct;
    icon: LucideIcon;
    launchable: boolean;
    onLaunch: () => void;
}) {
    return (
        <Button
            variant="ghost"
            className="h-auto min-w-0 flex-col gap-2 px-2 py-3"
            disabled={!launchable}
            asChild={launchable}
        >
            {launchable ? (
                product.id === 'core' ? (
                    <Link
                        href={product.href}
                        title={product.description}
                        onClick={onLaunch}
                    >
                        <span className="flex size-10 items-center justify-center rounded-lg bg-muted text-foreground">
                            <Icon />
                        </span>
                        <span className="w-full truncate text-xs">
                            {product.name}
                        </span>
                    </Link>
                ) : (
                    <a
                        href={product.href}
                        title={product.description}
                        onClick={onLaunch}
                    >
                        <span className="flex size-10 items-center justify-center rounded-lg bg-muted text-foreground">
                            <Icon />
                        </span>
                        <span className="w-full truncate text-xs">
                            {product.name}
                        </span>
                    </a>
                )
            ) : (
                <span title="Produk belum memiliki akses untuk akun ini">
                    <span className="flex size-10 items-center justify-center rounded-lg bg-muted text-foreground">
                        <Icon />
                    </span>
                    <span className="w-full truncate text-xs">
                        {product.name}
                    </span>
                </span>
            )}
        </Button>
    );
}

export function ProductLauncher() {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const { entitledProducts, launchableProducts } = usePage().props;
    const products: EntitledProduct[] = [
        {
            id: 'core',
            name: 'Core',
            description: 'CoreERP workspace and settings.',
            href: '/dashboard',
        },
        ...entitledProducts,
    ];
    const launchableIds = new Set(
        launchableProducts.map((product) => product.id),
    );
    const filteredProducts = products.filter(
        (product, index, all) =>
            all.findIndex((item) => item.id === product.id) === index &&
            product.name
                .toLocaleLowerCase()
                .includes(search.trim().toLocaleLowerCase()),
    );

    return (
        <Popover
            open={open}
            onOpenChange={(nextOpen) => {
                setOpen(nextOpen);

                if (!nextOpen) {
                    setSearch('');
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Katalog aplikasi"
                >
                    <Grid3X3 />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[min(30rem,calc(100vw-1rem))] p-3"
            >
                <PopoverHeader className="p-2">
                    <PopoverTitle>Aplikasi</PopoverTitle>
                    <PopoverDescription>
                        {launchableProducts.length} produk bisnis siap dibuka.
                        {entitledProducts.length > launchableProducts.length &&
                            ` ${entitledProducts.length - launchableProducts.length} lainnya masih dipasang atau belum diberikan kepada akun Anda.`}
                    </PopoverDescription>
                </PopoverHeader>
                <Input
                    aria-label="Cari aplikasi"
                    placeholder="Cari aplikasi…"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
                <ScrollArea className="max-h-96">
                    <div className="grid grid-cols-3 gap-1 py-3 sm:grid-cols-4">
                        {filteredProducts.map((product) => (
                            <ProductLink
                                key={product.id}
                                product={product}
                                icon={
                                    product.id === 'core'
                                        ? Building2
                                        : (productIcons[product.id] ?? Boxes)
                                }
                                launchable={
                                    product.id === 'core' ||
                                    launchableIds.has(product.id)
                                }
                                onLaunch={() => setOpen(false)}
                            />
                        ))}
                    </div>
                    {filteredProducts.length === 0 && (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            Tidak ada aplikasi yang cocok.
                        </p>
                    )}
                </ScrollArea>
            </PopoverContent>
        </Popover>
    );
}

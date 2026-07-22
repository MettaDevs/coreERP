import { Link, usePage } from '@inertiajs/react';
import {
    Boxes,
    Building2,
    Grid3X3,
    PackageSearch,
    ShoppingCart,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import type { EntitledProduct } from '@/types/auth';

const productIcons: Record<string, LucideIcon> = {
    procurement: ShoppingCart,
    'management-asset': PackageSearch,
};

function ProductLink({
    product,
    icon: Icon,
    launchable,
}: {
    product: EntitledProduct;
    icon: LucideIcon;
    launchable: boolean;
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
                    <Link href={product.href} title={product.description}>
                        <span className="flex size-10 items-center justify-center rounded-lg bg-muted text-foreground">
                            <Icon />
                        </span>
                        <span className="w-full truncate text-xs">{product.name}</span>
                    </Link>
                ) : (
                    <a href={product.href} title={product.description}>
                        <span className="flex size-10 items-center justify-center rounded-lg bg-muted text-foreground">
                            <Icon />
                        </span>
                        <span className="w-full truncate text-xs">{product.name}</span>
                    </a>
                )
            ) : (
                <span title="Produk belum memiliki akses untuk akun ini">
                    <span className="flex size-10 items-center justify-center rounded-lg bg-muted text-foreground">
                        <Icon />
                    </span>
                    <span className="w-full truncate text-xs">{product.name}</span>
                </span>
            )}
        </Button>
    );
}

export function ProductLauncher() {
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
    const launchableIds = new Set(launchableProducts.map((product) => product.id));

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Produk tersedia"
                >
                    <Grid3X3 />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 p-2">
                <PopoverHeader className="p-2">
                    <PopoverTitle>Produk</PopoverTitle>
                    <PopoverDescription>
                        {launchableProducts.length} produk bisnis siap dibuka.
                        {entitledProducts.length > launchableProducts.length &&
                            ` ${entitledProducts.length - launchableProducts.length} lainnya masih dipasang atau belum diberikan kepada akun Anda.`}
                    </PopoverDescription>
                </PopoverHeader>
                <ScrollArea className="max-h-96">
                    <div className="grid grid-cols-3 gap-1 p-1">
                        {products.filter((product, index, all) => all.findIndex((item) => item.id === product.id) === index).map((product) => (
                            <ProductLink
                                key={product.id}
                                product={product}
                                icon={
                                    product.id === 'core'
                                        ? Building2
                                        : (productIcons[product.id] ?? Boxes)
                                }
                                launchable={product.id === 'core' || launchableIds.has(product.id)}
                            />
                        ))}
                    </div>
                </ScrollArea>
            </PopoverContent>
        </Popover>
    );
}

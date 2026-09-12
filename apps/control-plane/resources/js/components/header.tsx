import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@apperp/ui/breadcrumb';
import { SidebarTrigger } from '@apperp/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';
import { activeItem } from '@/lib/navigation';

/**
 * Sepadan dengan `app-sidebar-header.tsx` milik Core, dikurangi hampir seluruh isinya.
 *
 * Tidak ada pemilih workspace maupun cascade tenant, dan itu bukan penyederhanaan sementara:
 * operator bukan anggota tenant mana pun, jadi tidak ada yang dapat dipilihnya. Ikut hilang karena
 * alasan yang sama — peluncur produk, lonceng notifikasi, dan palet perintah — semuanya berdiri di
 * atas module dan keanggotaan yang tidak dimiliki konsol ini.
 *
 * Yang menggantikannya jejak alamat, karena hanya itu yang berubah dari layar ke layar di sini.
 */
export default function Header({
    title,
    scrolled,
}: {
    title: string;
    scrolled: boolean;
}) {
    const { url } = usePage();
    const section = activeItem(url);

    return (
        <header
            className={
                scrolled
                    ? 'bg-background sticky top-0 z-20 h-14 shrink-0 rounded-none border-b shadow-sm transition-[border-radius,box-shadow] duration-200'
                    : 'bg-background sticky top-0 z-20 h-14 shrink-0 rounded-t-2xl border-b transition-[border-radius,box-shadow] duration-200'
            }
        >
            <div className="flex h-full items-center gap-3 px-5">
                <SidebarTrigger className="shrink-0" />
                <div className="bg-border hidden h-6 w-px sm:block" />
                <Breadcrumb>
                    <BreadcrumbList>
                        {section && section.title !== title && (
                            <>
                                <BreadcrumbItem>
                                    <BreadcrumbLink asChild>
                                        <Link href={section.href}>
                                            {section.title}
                                        </Link>
                                    </BreadcrumbLink>
                                </BreadcrumbItem>
                                <BreadcrumbSeparator />
                            </>
                        )}
                        <BreadcrumbItem>
                            <BreadcrumbPage className="truncate">
                                {title}
                            </BreadcrumbPage>
                        </BreadcrumbItem>
                    </BreadcrumbList>
                </Breadcrumb>
            </div>
        </header>
    );
}

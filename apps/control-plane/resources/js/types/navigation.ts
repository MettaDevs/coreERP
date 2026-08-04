import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

export type HostedNavigationItem = {
    id: string;
    label: string;
    href: string;
};

export type HostedNavigationRail = {
    id: string;
    label: string;
    href: string;
    items: HostedNavigationItem[];
};

export type HostedNavigation = {
    rails: HostedNavigationRail[];
    activeItemId: string | null;
};

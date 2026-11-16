import { usePage } from '@inertiajs/react';
import type { SVGAttributes } from 'react';

/**
 * Logo aplikasi. Alamatnya datang dari server: logo pengganti milik tenant bila ada, selain itu
 * logo bawaan. Gambar pengganti dipaskan ke kotak yang sama tanpa mengubah perbandingannya.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    const { branding } = usePage().props;

    return (
        <svg
            {...props}
            viewBox="0 0 570 426"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <image
                href={branding?.logo_url ?? '/brand/coreerp-logo.png'}
                width="570"
                height="426"
            />
        </svg>
    );
}

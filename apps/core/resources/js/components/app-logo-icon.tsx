import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 570 426"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <image href="/brand/coreerp-logo.png" width="570" height="426" />
        </svg>
    );
}

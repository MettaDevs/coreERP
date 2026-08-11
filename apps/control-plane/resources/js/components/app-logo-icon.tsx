import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" {...props}>
            {/* Top Cyan/Light Blue Slanted Parallelogram */}
            <path
                d="M 45 14 L 76 14 L 41 58 L 7 58 Z"
                fill="#20A0E8"
            />
            {/* Bottom Navy/Dark Blue Slanted Parallelogram */}
            <path
                d="M 59 41 L 93 41 L 58 86 L 24 86 Z"
                fill="#1B365D"
            />
        </svg>
    );
}

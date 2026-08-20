import { useId } from 'react';
import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    const id = useId();
    const gradTopId = `sanataLogoGradTop-${id}`;
    const gradBottomId = `sanataLogoGradBottom-${id}`;

    return (
        <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" {...props}>
            <defs>
                <linearGradient id={gradTopId} x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stopColor="#38BDF8" />
                    <stop offset="100%" stopColor="#00C9C8" />
                </linearGradient>
                <linearGradient id={gradBottomId} x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stopColor="#08BFC3" />
                    <stop offset="100%" stopColor="#0096A6" />
                </linearGradient>
            </defs>
            {/* Top Cyan/Light Blue Slanted Parallelogram */}
            <path
                d={`M 45 14 L 76 14 L 41 58 L 7 58 Z`}
                fill={`url(#${gradTopId})`}
            />
            {/* Bottom Teal Slanted Parallelogram */}
            <path
                d={`M 59 41 L 93 41 L 58 86 L 24 86 Z`}
                fill={`url(#${gradBottomId})`}
            />
        </svg>
    );
}

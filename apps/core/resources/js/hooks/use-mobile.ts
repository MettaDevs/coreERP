import * as React from 'react';

const MOBILE_BREAKPOINT = 768;
const MOBILE_QUERY = `(max-width: ${MOBILE_BREAKPOINT - 1}px)`;

function subscribe(onChange: () => void) {
    const mql = window.matchMedia(MOBILE_QUERY);

    mql.addEventListener('change', onChange);

    return () => mql.removeEventListener('change', onChange);
}

/**
 * Lebar layar dibaca lewat useSyncExternalStore, bukan useState di dalam useEffect.
 * Cara lama membuat render pertama selalu memakai nilai desktop lalu segera render
 * ulang, dan React 19 melaporkannya sebagai cascading render.
 */
export function useIsMobile() {
    return React.useSyncExternalStore(
        subscribe,
        () => window.innerWidth < MOBILE_BREAKPOINT,
        () => false,
    );
}

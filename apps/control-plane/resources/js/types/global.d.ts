export type Operator = { name: string; email: string };

/*
 * Props bersama dari `HandleInertiaRequests`, dinyatakan sekali untuk seluruh konsol.
 *
 * Tanpa ini setiap komponen yang membacanya harus menulis ulang bentuknya sebagai generic
 * `usePage<...>()`, dan bentuk yang ditulis di banyak tempat adalah bentuk yang kelak berbeda di
 * salah satunya — biasanya di tempat yang paling jarang dibuka.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            operator: Operator | null;
            message: string | null;
            [key: string]: unknown;
        };
    }
}

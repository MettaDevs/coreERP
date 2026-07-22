import { Head } from '@inertiajs/react';
import { DotLottie } from '@lottiefiles/dotlottie-web';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

const lottieFiles = import.meta.glob('../assets/lottie/*.lottie', {
    eager: true,
    query: '?url',
    import: 'default',
}) as Record<string, string>;

const backgroundNames = new Set(['404', 'login', 'online-learning']);

const animations = Object.entries(lottieFiles)
    .map(([path, src]) => ({
        name: path.split('/').pop()?.replace('.lottie', '') ?? path,
        src,
    }))
    .sort((a, b) => a.name.localeCompare(b.name));

function LottieCanvas({ src }: { src: string }) {
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        if (!canvasRef.current) return;

        const player = new DotLottie({
            canvas: canvasRef.current,
            src,
            loop: true,
            autoplay: true,
            layout: { fit: 'contain' },
        });

        return () => player.destroy();
    }, [src]);

    return <canvas ref={canvasRef} className="size-full" />;
}

function LottiePlayer({ src }: { src: string }) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [isNearViewport, setIsNearViewport] = useState(false);

    useEffect(() => {
        if (!containerRef.current) return;

        const observer = new IntersectionObserver(
            ([entry]) => setIsNearViewport(entry.isIntersecting),
            { rootMargin: '160px 0px' },
        );

        observer.observe(containerRef.current);

        return () => observer.disconnect();
    }, []);

    return (
        <div ref={containerRef} className="size-full">
            {isNearViewport ? <LottieCanvas src={src} /> : null}
        </div>
    );
}

function AnimationCard({ name, src }: { name: string; src: string }) {
    return (
        <Card className="gap-3 py-4">
            <CardContent className="flex aspect-square items-center justify-center px-4">
                <LottiePlayer src={src} />
            </CardContent>
            <CardHeader className="px-4">
                <CardTitle className="text-sm">{name}</CardTitle>
            </CardHeader>
        </Card>
    );
}

function AnimationGroup({
    title,
    description,
    items,
}: {
    title: string;
    description: string;
    items: typeof animations;
}) {
    return (
        <section className="flex flex-col gap-4">
            <div>
                <div className="flex items-center gap-2">
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <Badge variant="secondary">{items.length}</Badge>
                </div>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                {items.map((animation) => (
                    <AnimationCard key={animation.name} {...animation} />
                ))}
            </div>
        </section>
    );
}

export default function LottieGallery() {
    const backgrounds = animations.filter((animation) =>
        backgroundNames.has(animation.name),
    );
    const icons = animations.filter(
        (animation) => !backgroundNames.has(animation.name),
    );

    return (
        <>
            <Head title="Lottie Gallery" />
            <main className="min-h-screen bg-muted/30">
                <div className="flex w-full flex-col gap-6 p-4">
                    <header>
                        <h1 className="text-2xl font-semibold">
                            Lottie Gallery
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Preview animation untuk dikelompokkan dan dipakai
                            kembali di aplikasi.
                        </p>
                    </header>
                    <AnimationGroup
                        title="Icon animations"
                        description="Animasi kecil untuk status, aksi, dan empty state."
                        items={icons}
                    />
                    <AnimationGroup
                        title="Background animations"
                        description="Animasi besar untuk landing, login, dan halaman penuh."
                        items={backgrounds}
                    />
                </div>
            </main>
        </>
    );
}

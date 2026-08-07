import { CircleAlert } from 'lucide-react';

import { Tooltip, TooltipContent, TooltipTrigger } from '@apperp/ui/tooltip';

export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header
            className={
                variant === 'small'
                    ? 'flex items-center gap-1'
                    : 'mb-8 flex items-center gap-1'
            }
        >
            <h2
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium'
                        : 'text-xl font-semibold tracking-tight'
                }
            >
                {title}
            </h2>
            {description && (
                <Tooltip clickToPin>
                    <TooltipTrigger asChild>
                        <button
                            type="button"
                            className="shrink-0 text-muted-foreground hover:text-foreground"
                            aria-label={`Tentang ${title}`}
                        >
                            <CircleAlert className="size-4" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent side="left" className="max-w-72">
                        {description}
                    </TooltipContent>
                </Tooltip>
            )}
        </header>
    );
}

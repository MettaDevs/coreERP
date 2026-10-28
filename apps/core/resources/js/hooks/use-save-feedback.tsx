import { FormErrorSummary, useFormValidation } from '@apperp/ui/form-feedback';
import type { FormFeedback } from '@apperp/ui/form-feedback';
import { useRef, useState } from 'react';
import { saveErrorFeedback } from '@/lib/core-api';

export function useSaveFeedback<T extends HTMLElement = HTMLFormElement>(
    scopeKey: string | number | null = null,
) {
    const [failure, setFailure] = useState<{
        scopeKey: string | number | null;
        feedback: FormFeedback;
    } | null>(null);

    if (failure && failure.scopeKey !== scopeKey) {
        setFailure(null);
    }

    const scopeRef = useRef<T>(null);
    const clientFeedback = useFormValidation(scopeRef, scopeKey);
    const feedback =
        clientFeedback ??
        (failure?.scopeKey === scopeKey ? failure.feedback : null);

    return {
        scopeRef,
        clear: () => setFailure(null),
        report: (caught: unknown, operation: string) =>
            setFailure({
                scopeKey,
                feedback: saveErrorFeedback(caught, operation),
            }),
        summary: feedback ? (
            <FormErrorSummary feedback={feedback} scopeRef={scopeRef} />
        ) : null,
    };
}

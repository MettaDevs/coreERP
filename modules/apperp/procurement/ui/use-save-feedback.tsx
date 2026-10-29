import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import {
    FormErrorSummary,
    focusFeedbackField,
    useFormValidation,
} from '@apperp/ui/form-feedback';
import type { FormFeedback } from '@apperp/ui/form-feedback';
import { saveErrorFeedback } from './api';

export function useSaveFeedback(
    providedScope?: RefObject<HTMLElement | null>,
    recordKey?: string | null,
    revealFields?: (field: string) => void,
) {
    const ownScope = useRef<HTMLDivElement>(null);
    const scopeRef = providedScope ?? ownScope;
    const [state, setState] = useState<{
        recordKey: string | null | undefined;
        feedback: FormFeedback | null;
    }>({ recordKey, feedback: null });

    if (state.recordKey !== recordKey) {
        setState({ recordKey, feedback: null });
    }

    const clientFeedback = useFormValidation(scopeRef, recordKey ?? null);
    const feedback =
        clientFeedback ??
        (state.recordKey === recordKey ? state.feedback : null);
    const [focusRequest, setFocusRequest] = useState<{ field: string } | null>(
        null,
    );

    useEffect(() => {
        if (focusRequest && scopeRef.current) {
            if (!focusFeedbackField(scopeRef.current, focusRequest.field)) {
                focusFeedbackField(
                    scopeRef.current,
                    focusRequest.field.split('.')[0],
                );
            }
        }
    }, [focusRequest, scopeRef]);

    return {
        scopeRef: ownScope,
        reportSaveError: (caught: unknown, operation: string) =>
            setState({
                recordKey,
                feedback: saveErrorFeedback(caught, operation),
            }),
        clearSaveFeedback: () => setState({ recordKey, feedback: null }),
        fieldError: (field: string) =>
            feedback?.issues
                ?.filter(
                    (issue) =>
                        issue.field === field ||
                        issue.field.startsWith(`${field}.`),
                )
                .map((issue) => issue.message)
                .join(' ') || undefined,
        feedbackSummary: feedback ? (
            <FormErrorSummary
                feedback={feedback}
                scopeRef={scopeRef}
                onFocusField={(field) => {
                    revealFields?.(field);
                    setFocusRequest({ field });
                }}
            />
        ) : null,
    };
}

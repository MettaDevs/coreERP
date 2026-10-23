import { createSaveFeedback, FormErrorSummary } from '@apperp/ui/form-feedback';
import type { FormFeedback } from '@apperp/ui/form-feedback';
import type { UseFormSubmitOptions, VisitCallbacks } from '@inertiajs/core';
import { useState } from 'react';

type FailureCallbacks = Pick<
    VisitCallbacks,
    'onStart' | 'onHttpException' | 'onNetworkError'
>;
type FormRequestWrapper = {
    (): UseFormSubmitOptions & FailureCallbacks;
    <T extends UseFormSubmitOptions>(
        options: T,
    ): T & UseFormSubmitOptions & FailureCallbacks;
};
type RouterRequestWrapper = {
    (): FailureCallbacks & Pick<VisitCallbacks, 'onError'>;
    <T extends UseFormSubmitOptions>(
        options: T,
    ): T & FailureCallbacks & Pick<VisitCallbacks, 'onError'>;
};

export function createInertiaFeedbackCallbacks(
    operation: string,
    report: (feedback: FormFeedback | null) => void,
    options: UseFormSubmitOptions = {},
): FailureCallbacks {
    return {
        onStart: (visit) => {
            report(null);
            options.onStart?.(visit);
        },
        onHttpException: (response) => {
            report(
                createSaveFeedback({
                    operation,
                    status: response.status,
                    message:
                        'Server belum dapat menyelesaikan tindakan ini. Isian tetap tersedia. Hubungi pengelola aplikasi bila masalah berulang.',
                }),
            );
            options.onHttpException?.(response);

            return false;
        },
        onNetworkError: (error) => {
            report({
                title: 'Jawaban belum diterima',
                message:
                    'Jawaban belum dapat diterima. Isian tetap tersedia. Periksa hasil tindakan sebelum mengirim ulang.',
            });
            options.onNetworkError?.(error);

            return false;
        },
    };
}

function createFormRequestWrapper(
    operation: string,
    report: (feedback: FormFeedback | null) => void,
): FormRequestWrapper {
    function wrap(): UseFormSubmitOptions & FailureCallbacks;
    function wrap<T extends UseFormSubmitOptions>(
        options: T,
    ): T & UseFormSubmitOptions & FailureCallbacks;
    function wrap(options: UseFormSubmitOptions = {}) {
        return {
            ...options,
            ...createInertiaFeedbackCallbacks(operation, report, options),
        };
    }

    return wrap;
}

function createRouterRequestWrapper(
    operation: string,
    report: (feedback: FormFeedback | null) => void,
): RouterRequestWrapper {
    const wrap = createFormRequestWrapper(operation, report);

    function wrapRouter(): FailureCallbacks & Pick<VisitCallbacks, 'onError'>;
    function wrapRouter<T extends UseFormSubmitOptions>(
        options: T,
    ): T & FailureCallbacks & Pick<VisitCallbacks, 'onError'>;
    function wrapRouter(options: UseFormSubmitOptions = {}) {
        return {
            ...wrap(options),
            onError: (...args: Parameters<VisitCallbacks['onError']>) => {
                if (options.onError) {
                    options.onError(...args);
                } else {
                    report(
                        createSaveFeedback({
                            operation,
                            message: 'Periksa data yang ditandai.',
                            errors: args[0],
                            code: args[0].version ? 'stale_version' : null,
                        }),
                    );
                }
            },
        };
    }

    return wrapRouter;
}

export function useInertiaFeedback(
    operation = 'Tindakan belum dapat diselesaikan',
) {
    const [feedback, setFeedback] = useState<FormFeedback | null>(null);
    const wrap = createFormRequestWrapper(operation, setFeedback);
    const wrapRouter = createRouterRequestWrapper(operation, setFeedback);

    return {
        wrap,
        wrapRouter,
        summary: feedback ? <FormErrorSummary feedback={feedback} /> : null,
    };
}

export function withInertiaErrors<
    T extends UseFormSubmitOptions & { onError: VisitCallbacks['onError'] },
>(options: T): T & FailureCallbacks {
    return {
        ...options,
        ...createInertiaFeedbackCallbacks(
            'Tindakan belum dapat diselesaikan',
            (feedback) => {
                if (feedback) {
                    options.onError({
                        _form: feedback.details
                            ? 'Terjadi gangguan sistem. Isian masih tersedia. Hubungi pengelola aplikasi.'
                            : feedback.message,
                    });
                }
            },
            options,
        ),
    };
}

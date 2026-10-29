import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Field, FieldError } from '@apperp/ui/field';
import { Textarea } from '@apperp/ui/textarea';
import { Link, useForm } from '@inertiajs/react';
import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';

export type ApprovalRequest = {
    id: string;
    workflow_name: string;
    app_name: string;
    document_number: string;
    document_url: string | null;
    reason: string;
    email_notified_at: string | null;
    email_last_error: string | null;
};

export function ApprovalRequestCard({
    item,
    onDecided,
}: {
    item: ApprovalRequest;
    onDecided?: () => void;
}) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const form = useForm({ decision: '', comment: '' });
    const decide = (decision: 'approve' | 'reject') => {
        form.transform((data) => ({ ...data, decision }));
        form.post(
            `/workflow-inbox/${item.id}/decision`,
            wrapFormRequest({
                preserveScroll: true,
                onSuccess: onDecided,
            }),
        );
    };

    return (
        <article
            id={`request-${item.id}`}
            className="space-y-4 rounded-md border p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">{item.workflow_name}</p>
                    <p className="text-sm text-muted-foreground">
                        {item.app_name} · {item.document_number}
                    </p>
                </div>
                <Badge variant="secondary">Menunggu keputusan</Badge>
            </div>
            <>
                {formRequestSummary}
                <FormErrorHeader
                    errors={form.errors}
                    operation="Keputusan belum disimpan"
                />
            </>
            {item.reason && (
                <p className="text-sm whitespace-pre-wrap">{item.reason}</p>
            )}
            {item.document_url && (
                <Button asChild variant="outline">
                    <Link href={item.document_url}>Lihat dokumen</Link>
                </Button>
            )}
            {item.email_last_error && (
                <p className="text-sm text-muted-foreground">
                    Email belum terkirim. Permintaan ini tetap dapat diproses
                    dari aplikasi.
                </p>
            )}
            <Field data-error-field={'comment'}>
                <Textarea
                    label="Catatan keputusan"
                    data-error-field="comment"
                    aria-invalid={!!form.errors.comment}
                    value={form.data.comment}
                    onChange={(event) =>
                        form.setData('comment', event.target.value)
                    }
                />
                <FieldError>{form.errors.comment}</FieldError>
            </Field>
            {(form.errors.decision || form.errors.comment) && (
                <p role="alert" className="text-sm text-destructive">
                    {form.errors.decision || form.errors.comment}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    disabled={form.processing}
                    onClick={() => decide('approve')}
                >
                    Setujui
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={form.processing}
                    onClick={() => decide('reject')}
                >
                    Tolak
                </Button>
            </div>
        </article>
    );
}

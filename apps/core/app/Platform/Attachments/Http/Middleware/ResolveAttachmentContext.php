<?php

declare(strict_types=1);

namespace App\Platform\Attachments\Http\Middleware;

use App\Platform\Attachments\Models\DocumentAttachment;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Contracts\AttachmentRecordType;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use App\Platform\Modules\Http\Middleware\ResolveModuleContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menyiapkan rute lampiran dokumen (gap 7): jenis record induknya, lampirannya bila rute menyebut satu, dan
 * konteks module pemilik tabel induknya.
 *
 * Hak atas lampiran dijawab pemilik tabel induk lewat {@see AttachmentRecordType}. Pemilik yang berupa module
 * membaca permission dan kebijakan organisasinya dari konteks module, jadi konteks itu dipasang di sini lewat
 * {@see ResolveModuleContext}, sama persis dengan rute module sendiri: tanpa izin apa pun di module itu,
 * permintaannya berhenti 403 di sana. Tabel milik Core tidak memasang konteks module.
 *
 * Lampiran yang disebut lewat id dicari di tenant aktif saja; id milik tenant lain dijawab 404, sama dengan id
 * yang tidak ada.
 */
final class ResolveAttachmentContext
{
    public const RECORD_TYPE = 'attachments.record_type';

    public const ATTACHMENT = 'attachments.attachment';

    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly AttachmentRecordTypes $types,
        private readonly ResolveModuleContext $moduleContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $membership = $this->workspace->membership($request);
        abort_if($membership === null, 403, 'Tidak ada tenant aktif untuk permintaan ini.');

        $attachmentId = $request->route('attachment');
        if (is_string($attachmentId)) {
            $attachment = DocumentAttachment::query()
                ->where('tenant_id', $membership->tenant_id)
                ->find($attachmentId);
            abort_if($attachment === null, 404, 'Lampiran tidak ditemukan.');
            $request->attributes->set(self::ATTACHMENT, $attachment);
            $recordType = $attachment->record_type;
        } else {
            $recordType = (string) $request->route('recordType');
        }

        $type = $this->types->for($recordType);
        abort_if($type === null, 404, 'Data ini tidak dapat diberi lampiran.');
        $request->attributes->set(self::RECORD_TYPE, $type);

        $moduleId = $type->moduleId();

        return $moduleId === null ? $next($request) : $this->moduleContext->handle($request, $next, $moduleId);
    }
}

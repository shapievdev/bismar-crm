<?php

declare(strict_types=1);

namespace App\Support\Lms;

final readonly class AttachmentDelivery
{
    /**
     * Types a browser may render in place. Everything else is served as a
     * download.
     *
     * This is an allow-list on purpose. HTML, SVG and similar can carry script,
     * and the storage bucket is a single origin shared by every uploaded file —
     * so a document rendered inline could read or overwrite its neighbours'
     * data. Forcing a download makes the browser save the file instead of
     * executing it, which is what lets HTML be accepted at all.
     */
    private const INLINE_MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/heic',
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'audio/mpeg',
        'audio/wav',
        'audio/mp4',
        'text/plain',
    ];

    public function isInline(?string $mimeType): bool
    {
        return in_array(strtolower((string) $mimeType), self::INLINE_MIME_TYPES, strict: true);
    }

    /**
     * The Content-Disposition the signed URL should respond with.
     *
     * Non-ASCII filenames are encoded per RFC 5987; the plain `filename`
     * fallback is stripped to ASCII so older clients still get something
     * usable rather than a mangled header.
     */
    public function contentDisposition(?string $mimeType, string $filename): string
    {
        $type = $this->isInline($mimeType) ? 'inline' : 'attachment';

        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'file';
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $type,
            $ascii,
            rawurlencode($filename),
        );
    }
}

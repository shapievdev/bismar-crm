<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Lesson attachments
    |--------------------------------------------------------------------------
    |
    | Attachments are stored on the `s3` disk. The size limit is expressed in
    | kilobytes to match Laravel's `max:` validation rule. Note that PHP's own
    | upload_max_filesize and post_max_size still apply and are usually lower —
    | raise them too if you increase this.
    |
    */

    'attachment_max_kb' => (int) env('LESSON_ATTACHMENT_MAX_KB', 51200),

    /*
    |--------------------------------------------------------------------------
    | Signed URL lifetime
    |--------------------------------------------------------------------------
    |
    | How long a download link for an attachment stays valid. Short enough that
    | a leaked link expires quickly, long enough to start a large download.
    |
    */

    'attachment_url_ttl_minutes' => (int) env('LESSON_ATTACHMENT_URL_TTL', 15),

];

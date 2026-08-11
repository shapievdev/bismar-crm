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
    | How long a signed link stays valid — for attachments, lesson video, course
    | covers and avatars alike.
    |
    | It has to outlast a sitting, not just the click that starts one: S3 checks
    | the signature on every range request, so a link that expires mid-lesson
    | stops the video the reader is already watching. An hour covers a long
    | recording; the cost is that a leaked link stays usable for that hour.
    |
    | Raise it for longer material rather than leaving readers to reload.
    |
    */

    'attachment_url_ttl_minutes' => (int) env('LESSON_ATTACHMENT_URL_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Lesson video
    |--------------------------------------------------------------------------
    |
    | Uploaded lesson videos are larger than ordinary attachments, so they get
    | their own limit. PHP's upload_max_filesize and post_max_size cap this in
    | practice — raise them to match, or the request is rejected by PHP before
    | Laravel ever validates it.
    |
    */

    'video_max_kb' => (int) env('LESSON_VIDEO_MAX_KB', 512000),

    /*
    |--------------------------------------------------------------------------
    | Расшифровки
    |--------------------------------------------------------------------------
    |
    | Расшифровка — обычный текст, и десяти мегабайт хватает на сутки записи с
    | большим запасом. Предел здесь не про место в хранилище: расшифровка
    | ложится в базу кусками, и файл, который в неё не помещается, почти
    | наверняка не расшифровка вовсе.
    |
    */

    'transcript_max_kb' => (int) env('LESSON_TRANSCRIPT_MAX_KB', 10240),

];

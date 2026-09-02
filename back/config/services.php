<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Google Диск: окно выбора файла в редакторе урока и документа.
     *
     * Обычно эти два значения заводят в настройках приложения — там их видит
     * тот, кто настраивает компанию, а не тот, у кого есть доступ к серверу.
     * Здесь остаётся запасной путь: заполненные переменные окружения работают,
     * пока в настройках пусто (см. App\Support\Lms\GoogleSettings).
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'api_key' => env('GOOGLE_API_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

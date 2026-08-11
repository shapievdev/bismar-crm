<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Lesson attachments live here. A silent false on a failed upload
            // would leave a lesson pointing at an object that does not exist,
            // so storage errors must surface as exceptions.
            'throw' => true,
            'report' => false,

            /*
             * Сроки ожидания. Без них SDK ждёт ответа хранилища сколько угодно.
             *
             * Хранилище — чужой сервис за сетью, и сеть до него отваливается:
             * соединение при этом устанавливается, а ответ не приходит вовсе.
             * Один такой запрос — загрузка аватарки — занимал процесс целиком, а
             * встроенный сервер разработки однопроцессный: вставало всё
             * приложение, а не одна страница.
             *
             * Загрузка большого файла идёт дольше ожидания ответа на запрос,
             * поэтому общий срок задан щедро, а срок на соединение — скупо: если
             * до хранилища не достучаться, это видно за секунды.
             */
            'http' => [
                'connect_timeout' => (int) env('AWS_CONNECT_TIMEOUT', 5),
                'timeout' => (int) env('AWS_TIMEOUT', 60),
            ],

            /*
             * Повторов меньше, чем по умолчанию (три). Недоступное хранилище
             * умножает на них каждое ожидание, а сотрудник в это время смотрит
             * на крутящийся индикатор.
             */
            'retries' => (int) env('AWS_RETRIES', 1),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

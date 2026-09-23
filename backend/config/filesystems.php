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

        /*
         * ページ画像の置き場。
         *
         * 開発では cdn サービスが配信している cdn/public/ をそのままマウントする。
         * 本番 (Cloud Run) にはローカルディスクが無い (書けてもインスタンスが
         * 消えれば失われる) ので、CDN_DISK=gcs で GCS バケットに切り替える。
         *
         * 差し替わるのはこの定義だけで、Storage::disk('cdn') を呼んでいる
         * コントローラ側は変更不要。
         */
        'cdn' => env('CDN_DISK') === 'gcs'
            ? [
                'driver' => 'gcs',
                'bucket' => env('CDN_BUCKET'),
                'project_id' => env('GOOGLE_CLOUD_PROJECT'),
                // 開発でエミュレータに向けるとき用。本番では空のままにする
                'endpoint' => env('CDN_GCS_ENDPOINT'),
                'visibility' => 'public',
                'throw' => true,
            ]
            : [
                'driver' => 'local',
                'root' => env('CDN_ROOT', '/var/www/cdn'),
                'throw' => true,
                'visibility' => 'public',
                'directory_visibility' => 'public',
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
            'throw' => false,
            'report' => false,
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

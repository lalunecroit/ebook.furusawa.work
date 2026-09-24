<?php

namespace App\Providers;

use Google\Auth\Credentials\InsecureCredentials;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGcsDriver();
    }

    /**
     * Storage の gcs ドライバを登録する。
     *
     * Laravel が同梱しているのは local / s3 / ftp / sftp だけなので、
     * league/flysystem-google-cloud-storage を自分で繋ぐ。
     * 使うのは config/filesystems.php の cdn ディスク (CDN_DISK=gcs のとき)。
     */
    private function registerGcsDriver(): void
    {
        Storage::extend('gcs', function ($app, array $config): FilesystemAdapter {
            $endpoint = $config['endpoint'] ?? null;

            $client = new StorageClient(array_filter([
                'projectId' => $config['project_id'] ?? null,
                // 開発でエミュレータ (fake-gcs-server) に向けるとき用。本番では空。
                'apiEndpoint' => $endpoint,
                // 本番では何も渡さない。Cloud Run のサービスアカウントが
                // ADC として拾われる。エンドポイントを差し替えているときだけ、
                // 認証の無いエミュレータに合わせて資格情報を無効化する。
                'credentialsFetcher' => $endpoint ? new InsecureCredentials() : null,
            ]));

            $adapter = new GoogleCloudStorageAdapter(
                $client->bucket($config['bucket']),
                $config['prefix'] ?? '',
                // バケットは均一なバケットレベルのアクセス (allUsers:objectViewer を
                // バケットに付ける) 前提。この設定ではオブジェクト単位の ACL が
                // 使えず、visibility を設定しようとすると 400 になるため無効化する。
                new UniformBucketLevelAccessVisibility(),
            );

            return new FilesystemAdapter(new Flysystem($adapter, $config), $adapter, $config);
        });
    }
}

<?php

namespace App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->passComposeEnvironmentToServeCommand();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * `artisan serve` に compose の environment を引き継がせる。
     *
     * serve は PHP ビルトインサーバを子プロセスとして起動するが、そのとき
     * ServeCommand::$passthroughVariables のホワイトリストに載っている環境変数しか
     * 渡さない (ServeCommand::shouldPassThroughEnvironmentVariable)。
     * そのため compose の environment で DB_HOST=db を渡しても子プロセスには届かず、
     * リクエスト処理側は .env の DB_HOST=127.0.0.1 (ホストから artisan を叩くとき用) を
     * 読んでしまい "Connection refused" になる。
     *
     * ここで compose 側で渡している変数を通過リストに足して、
     * コンテナ内では compose の値が効くようにする。
     * (CDN_BASE_URL・APP_DEBUG・APP_TIMEZONE も同じ理由で無視されていた)
     */
    private function passComposeEnvironmentToServeCommand(): void
    {
        if (! class_exists(ServeCommand::class)) {
            return;
        }

        ServeCommand::$passthroughVariables = array_unique(array_merge(
            ServeCommand::$passthroughVariables,
            [
                'DB_CONNECTION',
                'DB_HOST',
                'DB_PORT',
                'DB_DATABASE',
                'DB_USERNAME',
                'DB_PASSWORD',
                'CDN_BASE_URL',
                'APP_DEBUG',
                'APP_TIMEZONE',
            ],
        ));
    }
}

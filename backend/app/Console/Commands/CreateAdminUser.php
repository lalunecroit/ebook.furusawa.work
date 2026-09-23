<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * 管理画面にログインできる利用者を作る。
 *
 * 管理画面には登録画面を用意していない (誰でも管理者になれてしまうため)。
 * 管理者はサーバに入れる人がこのコマンドで作る。
 *
 *   docker compose exec backend php artisan admin:user
 *   docker compose exec backend php artisan admin:user admin@example.com --name=管理者
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:user
                            {email? : ログインに使うメールアドレス}
                            {--name= : 表示名 (省略時はメールアドレスの @ より前)}';

    protected $description = '管理画面にログインできる利用者を作成する';

    public function handle(): int
    {
        $email = $this->argument('email') ?? text('メールアドレス', required: true);
        $name = $this->option('name') ?: strstr($email, '@', true);

        // パスワードはコマンドライン引数で受け取らない (シェルの履歴に残るため)
        $password = password('パスワード (8文字以上)', required: true);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // User の casts で password は 'hashed' なので、ここではハッシュ化しなくてよい
        User::create(['email' => $email, 'name' => $name, 'password' => $password]);

        $this->info("管理者 {$email} を作成しました。");

        return self::SUCCESS;
    }
}

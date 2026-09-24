<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // テストユーザを作るのは開発とテストのときだけ。
        //
        // 本番で artisan db:seed を叩かれても作らせない。雛形のままの
        // Test User が本番に居座るのを防ぐ意味と、UserFactory が使う fake() が
        // fakerphp/faker (dev 依存) の関数で、--no-dev でビルドした本番
        // イメージには存在しない、という二重の理由がある。
        //
        // 本番で投入したいのは書籍だけなので、BookSeeder は環境を問わず呼ぶ。
        // BookSeeder と同じく、何度流しても同じ状態になるようにする
        if (app()->environment('local', 'testing')
            && ! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        $this->call(BookSeeder::class);
    }
}

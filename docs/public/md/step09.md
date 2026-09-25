# fix(backend): 本番の動作確認で見つかった問題を直す

Step.08 の D 段で LB・証明書・DNS がそろい、本番が外から見られる状態になりました。
このステップでは実際に本番を触って確認し、そこで見つかった問題を直します。
あわせて README を、ローカルと本番の両方の構成が分かる形に書き直しました。

| # | コミット | 内容 |
|---|---|---|
| 1 | backend: 本番のシードは書籍だけにする | `DatabaseSeeder` のテストユーザを開発環境だけに限定し、冪等にする |
| 2 | backend: admin ホストは管理画面にリダイレクト | `admin.` の `/` を `/admin/books` へ。テスト 2 本 |
| 3 | README の更新 | 概要、ローカルと本番の構成・立ち上げ手順 |
| 4 | backend: 初回起動時に migration を実施 | 開発用 entrypoint でマイグレーションを流す |

---

## 1. 本番のシードは書籍だけにする

本番の DB はマイグレーション直後で空なので、サンプルの書籍を入れようとしました。
Cloud Run Jobs は、実行時に引数を上書きできます。

```bash
gcloud run jobs execute ebook-migrate --region asia-northeast1 --wait \
  --args=artisan,db:seed,--force
```

これが次のエラーで落ちました。

```
Call to undefined function Database\Factories\fake()
In UserFactory.php line 28
```

**原因は、`DatabaseSeeder` が Laravel の雛形のままだったことです。**

```php
User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@example.com',
]);

$this->call(BookSeeder::class);
```

`UserFactory` が使う `fake()` は `fakerphp/faker` の関数で、これは **dev 依存**です。
本番イメージは `composer install --no-dev` でビルドしているので入っていません。
書籍を入れる `BookSeeder` のほうは JSON カタログを読む作りで、`fake()` を使っていません。

**そもそも、本番に `Test User` を作られては困ります。** 今回は Faker が無くて偶然止まりましたが、止まるべき理由はそこではありません。
テストユーザは開発とテストのときだけ作り、書籍は環境を問わず入れる形にしました。

```php
if (app()->environment('local', 'testing')
    && ! User::where('email', 'test@example.com')->exists()) {
    User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);
}

$this->call(BookSeeder::class);
```

**存在チェックも足しました。** 元の形では、2 回目の `db:seed` がメールアドレスの一意制約で落ちていました。
`BookSeeder` は `updateOrCreate` で何度流しても同じ状態になるので、テストユーザのほうもそれに揃えています。

これで本番では `--class` を指定しなくても、`db:seed` が書籍だけを入れます。

## 2. admin ホストの `/` を管理画面へ

`https://admin.ebook.furusawa.work/` を開くと、Laravel の初期画面（welcome）が出ていました。
管理画面の入口は `/admin/books` なので、`admin.` のホストで `/` に来たらそこへ飛ばします。

```php
// routes/web.php
if ($adminHost = config('app.admin_host')) {
    Route::domain($adminHost)->group(function (): void {
        Route::redirect('/', '/admin/books');
    });
}

Route::get('/', function () {
    return view('welcome');
});
```

**`bootstrap/app.php` の管理画面グループではなく `web.php` に置いているのが要点です。**
welcome の `/` はホストを限定していないので、全ホストに当たります。
しかも `web.php` は、管理画面を登録する `then:` コールバックより先に読まれます。
あとから domain 付きの `/` を足しても、先に登録された welcome が勝ってしまうので、**welcome より前に**置く必要があります。

**`ADMIN_HOST` が空の開発環境では、この経路自体が生えません。**
開発は api も admin も `localhost` で兼ねているので、`http://localhost:8000` は従来どおり welcome が出ます（README の立ち上げ手順で、Laravel が起動したかの確認に使っています）。

テストは `AdminHostTest` に 2 本足しました。

- `admin.` の `/` が `/admin/books` へリダイレクトする
- `api.` / `www.` の `/` は従来どおり 200（管理画面の存在を匂わせない）

## 3. 開発環境の起動時にマイグレーションを流す

Step.07 でセッションを DB に置く形にしたので（`SESSION_DRIVER=database`）、**`sessions` テーブルが無いと最初のリクエストから 500 になります。**
新しく clone して `docker compose up -d` しただけの状態がこれで、README の手順どおりに進めても Laravel の画面が出ません。

開発用の entrypoint で、起動のたびにマイグレーションを流すようにしました。

```sh
# backend/docker-entrypoint.sh（開発用）
echo "[entrypoint] マイグレーションを実行します"
php artisan migrate --force
```

`migrate` は適用済みなら何もしないので、毎回流して問題ありません。

**本番の entrypoint（`docker/prod/entrypoint.sh`）では流しません。** Cloud Run はインスタンスが同時に複数立つので、起動のたびに流すと競合します（Step.07 の 10）。
本番は Cloud Run Jobs（`ebook-migrate`）で単発に実行します。

README の立ち上げ手順も、これに合わせて変えています。

```bash
# 3. サンプルデータの投入（マイグレーションは起動時に済んでいる）
docker compose exec backend php artisan db:seed
```

## 4. README

これまで README はリポジトリ名だけでした。GitHub から来た人が最初に読むものとして、次の構成で書き直しました。

| 節 | 内容 |
|---|---|
| 冒頭 | 画像ベースの電子書籍サービスであること、公開 URL、技術スタック |
| 本番環境（GCP） | 構成図、ホストの向き先、GCP のリソース一覧、`infra/` のディレクトリ |
| ローカル環境 | 立ち上げ手順、立ち上がったあとの URL、構成図とサービス表 |

構成図は docs の Step.06（ローカル）と Step.07（本番）から引用しています。

---

## 本番への反映（手動）

この時点では CI/CD が無いので、backend の変更は手でイメージを作って差し替えました。

```bash
docker build --platform linux/amd64 -f backend/Dockerfile.prod \
  -t asia-northeast1-docker.pkg.dev/my-project-book-509215/app/api:v2 ./backend
docker push asia-northeast1-docker.pkg.dev/my-project-book-509215/app/api:v2

R=asia-northeast1-docker.pkg.dev/my-project-book-509215/app/api:v2
gcloud run services update ebook-api     --region asia-northeast1 --image "$R"
gcloud run services update ebook-admin   --region asia-northeast1 --image "$R"
gcloud run jobs     update ebook-migrate --region asia-northeast1 --image "$R"
```

**`v1` を上書きせず `v2` にしています。** 上書きするとロールバック先が消えます。
Artifact Registry の保持ポリシーで直近 10 世代は残るので、戻したくなったら同じコマンドでタグを差し替えるだけです。

**Terraform ではなく gcloud で差し替えるのは、イメージを Terraform の管理から外してあるためです**（`ignore_changes`、Step.07 の 4.2）。
イメージの更新は CI/CD の仕事にする想定で、Step.11 でこの手順がそのまま CD になります。

## 本番環境での確認方法

**本番で `artisan test` は流せませんし、流すべきでもありません。**

| 理由 | 実際どうなっているか |
|---|---|
| そもそも入っていない | `phpunit/phpunit` は `require-dev`。本番イメージには `vendor/phpunit` が無い |
| 仮に入れても DB を壊す | 9 クラス・80 件のテストが `RefreshDatabase` を使い、テーブルを作り直す |

`phpunit.xml` が SQLite のインメモリを `force="true"` で固定しているので（Step.03）、仮に実行できても本番 DB は見に行きませんが、依存する話ではありません。
テストはローカルか CI で流すものです。

本番の確認は、外から叩いて見る疎通確認で行います。

```bash
curl -s https://api.ebook.furusawa.work/api/health?deep=1       # DB まで
curl -s -o /dev/null -w '%{http_code}\n' https://www.ebook.furusawa.work/
curl -s -o /dev/null -w '%{http_code}\n' https://admin.ebook.furusawa.work/admin/login
curl -s -o /dev/null -w '%{http_code}\n' https://ebook.furusawa.work/     # 301
curl -s https://api.ebook.furusawa.work/api/books | python3 -m json.tool | head

gcloud run services logs read ebook-api --region asia-northeast1 --limit=50
```

### 本番の管理者アカウントを作る

`artisan admin:user` はパスワードを対話で聞く作りなので（シェルの履歴に残さないため / Step.06）、TTY の無い Cloud Run Jobs では動きません。
Step.08 の 4.1 で用意したプロキシ経由で、手元のコンテナから本番 DB に向けて流します。

```bash
docker compose --profile prod-db up -d cloudsql-proxy

docker compose exec \
  -e DB_HOST=cloudsql-proxy -e DB_PORT=3306 \
  -e DB_DATABASE=ebooks -e DB_USERNAME=ebooks \
  -e DB_PASSWORD="$(gcloud secrets versions access latest --secret=ebook-db-password)" \
  backend php artisan admin:user test@example.com --name=テストユーザ
```

`-T` を付けないのは、パスワードの入力に TTY が要るためです。
パスワードは bcrypt でハッシュ化され、`APP_KEY` に依存しないので、ローカルのコンテナから作っても本番で使えます。

---

## 動作確認

| 確認 | 結果 |
|---|---|
| DNS 6 ホスト | すべて同じ LB の IP |
| 証明書 | `ACTIVE` |
| www / docs / cdn / api / admin | すべて 200 |
| 80 → 443、apex → www | 301 |
| CORS（www → api） | `access-control-allow-origin: https://www.ebook.furusawa.work` |
| API が返す URL | `https://api.ebook…`（TrustProxies が効いている） |
| cdn の画像 | `cache-control: max-age=31536000, immutable` と `age` が付く（Cloud CDN がヒット） |
| `www.` の `/` | 200。backend bucket がディレクトリ URL に `index.html` を返す（Step.07 の 8 章の未確認事項が解消） |
| `db:seed` | 本番で書籍 10 冊。テストユーザは作られない |
| `admin.` の `/` | `/admin/books` へ 302 |
| テスト | 86 件すべて通過 |

## ハマりどころ

| 症状 | 原因 |
|---|---|
| 本番の `db:seed` が `Call to undefined function fake()` | 雛形の `User::factory()` が dev 依存の Faker を使う。本番イメージは `--no-dev` |
| `db:seed` の 2 回目が一意制約で落ちる | テストユーザを毎回 `create` していた。存在チェックを足して冪等にする |
| `User::factory()->createOrFirst()` が無い | ファクトリにはこのメソッドが無い。`exists()` で先に確かめる |
| domain 付きの `/` を足しても welcome が出る | ホストを限定しない welcome が先に登録されている。`web.php` の welcome より前に置く |
| `Route::domain(…)->redirect(…)` がエラー | `redirect` は `RouteRegistrar` の passthru に無い。`group` で囲って中で `Route::redirect` |
| clone 直後に Laravel の画面が 500 | `SESSION_DRIVER=database` なのに `sessions` テーブルが無い。開発用 entrypoint で `migrate` |

## 積み残し

- デプロイが手作業（→ Step.11 で CD にする）
- 管理画面は `users` の全員が管理者。権限の区別が無い
- JS の圧縮・難読化をするかどうか（→ Step.10 で判断）

🤖 Generated with [Claude Code](https://claude.com/claude-code)

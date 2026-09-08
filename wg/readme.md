# WireGuard Beacon

WireGuard の Peer が使用しているグローバル IP アドレスを HTTPS 経由で中央サーバーへ通知し、その情報から WireGuard の設定ファイルを生成するための簡易 Beacon システムです。

主に、各拠点のグローバル IP アドレスが固定されていない環境での利用を想定しています。

## 概要

スター型の WireGuard 構成で、各拠点から Beacon サーバーへ定期的に HTTPS POST を行います。

Beacon サーバーは HTTP 接続元の IP アドレスを取得し、その端末の WireGuard Endpoint として JSON ファイルへ保存します。

```text
             拠点A
          WireGuard Peer
               |
               | HTTPS POST
               v
        +----------------+
        | Beacon Server  |
        | wg-beacon.php  |
        +----------------+
               |
               | wg-beacon.json
               v
     +----------------------+
     | wg-create-config.php |
     +----------------------+
               |
               | WireGuard config
               v
        中央 WireGuard
```

WireGuard 用 UDP ポートについては、各拠点のルーターで WireGuard 端末へのポートフォワードを設定しておくことを前提としています。

## ファイル構成

### `wg-beacon.php`

Beacon サーバー本体です。

各クライアントから送信された Beacon を受信し、接続元 IP アドレスを WireGuard の Endpoint として保存します。

以下の処理をサポートしています。

| type     | 内容                            |
| -------- | ----------------------------- |
| `set`    | Beacon を登録し、Endpoint と更新日時を更新 |
| `show`   | 登録されている端末を HTML で一覧表示         |
| `csv`    | 登録情報を CSV 形式で出力               |
| `sample` | JSON 設定ファイルのサンプルを生成           |

データは以下の形式で管理します。

```json
{
    "sample_user": {
        "name": "Sample User",
        "pubkey": "YOUR_PUBLIC_KEY",
        "endpoint": "example.com",
        "port": 51820,
        "updated_at": "2026-09-08 12:00:00",
        "tunnel_ip": "172.30.255.2/32",
        "active": "active"
    }
}
```

主な項目は以下のとおりです。

| 項目           | 内容                    |
| ------------ | --------------------- |
| `name`       | 端末・拠点名                |
| `pubkey`     | WireGuard 公開鍵         |
| `endpoint`   | 現在のグローバル IP アドレス      |
| `port`       | WireGuard の待受 UDP ポート |
| `updated_at` | 最終 Beacon 受信日時        |
| `tunnel_ip`  | WireGuard トンネル内 IP    |
| `active`     | Peer を使用するかどうかを示す値    |

`set` 処理では `endpoint` と `updated_at` のみを更新します。

### `wg-beacon-client.ps1`

Windows 用 Beacon クライアントです。

PowerShell から `curl.exe` を使用して Beacon サーバーへ HTTPS POST を行います。

環境に合わせて以下を変更してください。

```powershell
$term_id = "user"
$postUrl = "https://example.com/api/ip"
$type    = "set"
$apikey  = "YOUR_SECRET_API_KEY"
```

Windows タスクスケジューラなどから定期的に実行することを想定しています。

### `wg-beacon-client.sh`

Linux 用 Beacon クライアントです。

`curl` を使用して Beacon サーバーへ HTTPS POST を行います。

環境に合わせて以下を変更してください。

```bash
term_id="user"
post_url="https://example.com/api/ip"
type="set"
apikey="YOUR_SECRET_API_KEY"
```

実行権限を設定します。

```bash
chmod 700 wg-beacon-client.sh
```

cron や systemd timer などから定期的に実行できます。

### `wg-create-config.php`

`wg-beacon.json` を読み込み、中央サーバー用の WireGuard 設定ファイルを生成します。

初期設定では中央 WireGuard インターフェースに以下を使用します。

```ini
[Interface]
PrivateKey = YOUR_WG_PRIVATE_KEY
Address = 172.30.255.1/24
```

JSON に登録された端末から Peer 設定を生成します。

```ini
[Peer]
# User: user01:拠点01
PublicKey = ...
AllowedIPs = 172.30.255.2/32
Endpoint = 203.0.113.10:51820
```

秘密鍵と JSON ファイルの場所を環境に合わせて変更してください。

```php
define('WG_PRIVATE_KEY', 'YOUR_WG_PRIVATE_KEY');
define('JSON_FILE', '/var/www/html/data/wg-beacon.json');
```

設定ファイルを生成するには、

```bash
php wg-create-config.php
```

を実行します。

出力先を指定する場合は、

```bash
php wg-create-config.php /etc/wireguard/wg0.conf
```

のように指定します。

出力先を省略した場合は、日時を付けた設定ファイル名が使用されます。

## Beacon サーバーの設定

`wg-beacon.php` の API キーを変更します。

```php
const API_KEY = 'YOUR_SECRET_API_KEY';
```

必要に応じてログファイルの場所も変更します。

```php
const LOG_FILE = '/var/log/apache2/wg-beacon.log';
```

Web サーバーの実行ユーザーが必要なファイルへ書き込めるよう、適切なパーミッションを設定してください。

## データディレクトリ

Beacon サーバーは `data` ディレクトリを使用します。

```text
data/
├── .htaccess
└── wg-beacon.json
```

ディレクトリが存在しない場合は自動的に作成されます。

`.htaccess` には、

```apache
Require all denied
```

が設定され、Web 経由で JSON ファイルを直接取得できないようにします。

Apache 側で `.htaccess` が有効になっている必要があります。

可能であれば、JSON データを DocumentRoot 外へ配置する構成を推奨します。

## WireGuard の構成例

中央サーバー:

```text
172.30.255.1
```

各 Peer:

```text
172.30.255.2
172.30.255.3
172.30.255.4
...
```

中央サーバーでは各 Peer に `/32` を割り当てます。

```ini
[Peer]
PublicKey = ...
AllowedIPs = 172.30.255.2/32
Endpoint = 203.0.113.10:51820
```

## 動作の流れ

```text
1. クライアント起動
        |
        v
2. Beacon サーバーへ HTTPS POST
        |
        v
3. REMOTE_ADDR からグローバル IP を取得
        |
        v
4. wg-beacon.json の endpoint を更新
        |
        v
5. wg-create-config.php を実行
        |
        v
6. WireGuard 設定ファイル生成
        |
        v
7. WireGuard に設定を反映
```

## セキュリティ上の注意

このプログラムでは API キー、WireGuard 公開鍵、Endpoint、WireGuard 秘密鍵などを扱います。

特に以下に注意してください。

* `YOUR_SECRET_API_KEY` は必ず変更してください。
* `YOUR_WG_PRIVATE_KEY` は絶対に GitHub へ登録しないでください。
* 実際に使用している API キーを GitHub へ登録しないでください。
* 実際の `wg-beacon.json` を GitHub へ登録しないことを推奨します。
* Beacon 通信には HTTPS を使用してください。
* クライアントの `curl -k` はサーバー証明書を検証しないため、本番環境では可能な限り使用しないでください。
* WireGuard 用 UDP ポートは必要な範囲だけファイアウォールで許可してください。
* `wg-create-config.php` は WireGuard 秘密鍵を扱うため、一般ユーザーや Web 経由から実行・閲覧できない場所へ配置してください。

## `.gitignore` 例

秘密情報や実データを誤って GitHub へ登録しないため、例えば以下を `.gitignore` に追加してください。

```gitignore
data/
*.log
*.conf
.env
```

必要に応じて実際の環境に合わせて変更してください。

## 注意

このプログラムは簡易的な WireGuard Endpoint 管理を目的としています。

Beacon によって取得できるのは HTTPS 接続時の送信元 IP アドレスです。WireGuard の UDP ポートについては、ルーター側で固定のポートフォワードを設定しておくことを前提としています。

NAT、CGNAT、MAP-E などのネットワーク構成によっては、外部から任意の UDP ポートへ接続できない場合があります。

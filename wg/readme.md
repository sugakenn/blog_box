# WireGuard Beacon

WireGuard の中央サーバーと複数の端末を管理するための小規模なツール群です。

中央サーバー側では、PHP の管理画面から WireGuard Peer の情報を JSON ファイルで管理し、その情報から WireGuard の設定ファイルを生成します。

Windows クライアント側では、タスクスケジューラから定期的に WireGuard Peer の Endpoint を再設定することで、DNS 名に対応する IP アドレスが変更された場合にも追従できるようにします。

## ファイル構成

```text
wg-terms.php
wg-create-config.php
wg-beacon.ps1
```

### wg-terms.php

中央 WireGuard サーバーの Peer 情報を管理する Web ページです。
ここで端末名などを公開鍵と結び付けてデータを保存しておき、wiregurdの設定ファイルは、wg-create-config.php経由で作成します。

設定ファイル上に存在しない既存のWireGurdのエントリーは無名で読み込むようになっています。

主な機能:

* Peer の新規登録
* Peer 情報の編集
* Peer の削除
* Peer 一覧表示
* CSV 出力
* JSON ファイルへの保存
* `wg show wg0 dump` による実際の Peer 情報の取得
* 最新 handshake 時刻の記録
* WireGuard に存在し JSON に存在しない Peer の検出・登録

Peer 情報は次のような JSON データとして保存されます。

```json
{
    "PUBLIC_KEY": {
        "name": "terminal01",
        "pubkey": "PUBLIC_KEY",
        "endpoint": "192.0.2.10",
        "port": "51820",
        "tunnel_ip": "192.168.255.2/32",
        "active": "active",
        "inserted_at": "2026-09-09 12:00:00",
        "last_connected_at": "2026-09-09 12:30:00"
    }
}
```

`last_connected_at` は WireGuard の latest handshake を取得して更新します。

### wg-create-config.php

`wg-terms.php` が管理する JSON ファイルから WireGuard の設定ファイルを生成します。

生成される設定の例:

```ini
[Interface]
PrivateKey = ...
ListenPort = 51820
Address = 192.168.255.1/24

[Peer]
PublicKey = ...
AllowedIPs = 192.168.255.2/32
```

Peer に Endpoint と Port が設定されている場合は、Endpoint も出力します。

IPv6 Endpoint の場合は、

```ini
Endpoint = [2001:db8::1]:51820
```

の形式で出力します。

このプログラムは WireGuard の PrivateKey を扱うため、Web から直接実行・閲覧できる場所には配置しないでください。

## wg-beacon.ps1

Windows の WireGuard クライアント側で定期実行する PowerShell スクリプトです。

WireGuard の Peer Endpoint を定期的に再設定します。

```powershell
wg.exe set wg0 peer <PUBLIC_KEY> endpoint example.jp:51820
```

これにより、Endpoint にホスト名を使用している場合、DNS の参照結果が変化しても定期的に新しい IP アドレスへ更新できます。

Endpoint 更新後、中央サーバーの WireGuard アドレスへ ping を実行して結果をログへ保存します。

## 想定構成

```text
                    Internet
                       │
                       │
              ┌────────▼────────┐
              │ WireGuard Server│
              │      wg0        │
              │ 192.168.255.1/24 │
              └────────┬────────┘
                       │
              wg-terms.php
                       │
                wg-term.json
                       │
             wg-create-config.php
                       │
                  wg0.conf


        Windows Client
              │
        WireGuard wg0
              │
        wg-beacon.ps1
              │
       定期的にEndpoint更新
```

## 中央サーバー側

### 必要環境

* Linux
* WireGuard / wireguard-tools
* PHP 8 以降
* Apache 等の Web サーバー
* HTTPS

### wg-terms.php

`wg-terms.php` を Web サーバー(要SSL)へ配置します。

データは、

```text
data/wg-term.json
```

に保存されます。

`data` ディレクトリが存在しない場合は自動作成されます。

Apache から JSON ファイルを直接取得されないよう、`data/.htaccess` には次の設定が自動生成されます。

```apache
Require all denied
```

### HTTPS

Peer の登録・編集・削除では Double Submit Cookie による CSRF 対策を使用しています。

Cookie に `Secure` 属性を使用するため、HTTPS でアクセスしてください。

## wg コマンドの実行権限

`wg-terms.php` は Peer の状態を取得するため、

```bash
wg show wg0 dump
```

を実行します。

Web サーバーを `www-data` で動作させている場合は、必要なコマンドだけを sudo で許可します。

`visudo` を使用して設定します。

```bash
sudo visudo -f /etc/sudoers.d/wg-term
```

例:

```text
www-data ALL=(root) NOPASSWD: /usr/bin/wg show wg0 dump
```

動作確認:

```bash
sudo -u www-data sudo /usr/bin/wg show wg0 dump
```

`www-data` に WireGuard 全般の管理権限を与えるのではなく、必要なコマンドだけを許可してください。

## WireGuard 設定ファイルの生成

`wg-create-config.php` の次の値を環境に合わせて変更します。

```php
define('WG_PRIVATE_KEY', 'YOUR_WG_PRIVATE_KEY');
define('LISTEN_PORT', '51820');
define('JSON_FILE', '/var/www/html/data/wg-term.json');
```

実行例:

```bash
php wg-create-config.php
```

**このファイルに直接プライベートキーを書き込みますので、管理者権限のあるユーザー以外は参照もできないようにしてください。**

出力先を指定する場合:

```bash
php wg-create-config.php /etc/wireguard/wg0.conf
```

PrivateKey を含む設定ファイルを生成するため、root または適切に制限されたユーザーから実行してください。

## Windows クライアント

`wg-beacon.ps1` の設定部分を環境に合わせて変更します。

```powershell
$wg = "C:\Program Files\WireGuard\wg.exe"
$pubkey = "中央サーバーPeerのPUBLIC_KEY"
$net = "wg0"
$endpoint = "example.jp:51820"
$logFile = "C:\ProgramData\WGBeacon\wg-beacon.log"
$pingIp = "192.168.255.1"
```

### タスクスケジューラ

Windows タスクスケジューラから定期実行します。

設定例:

* 実行ユーザー: `SYSTEM`
* トリガー: 任意のユーザーのログオン時
* 遅延: 1分
* 繰り返し: 1時間
* 期間: 無期限
* ネットワーク接続が利用可能な場合に実行

プログラム:

```text
powershell.exe
```

引数:

```text
-file "C:\ProgramData\WGBeacon\wg-beacon.ps1"
```

開始:

```text
C:\ProgramData\WGBeacon
```

スクリプトファイルは一般ユーザーから変更できない場所へ配置し、一般ユーザーには書き込み権限を与えないでください。


## 注意

このツールは特定の WireGuard 構成を簡単に管理することを目的としたものです。

使用するネットワークアドレス、インターフェース名、ファイルパス、Web サーバーの実行ユーザーなどは環境に合わせて変更してください。

今回紹介したスター型接続を前提としておりますので、AllowIPに複数ネットワークを入れることは想定していません。



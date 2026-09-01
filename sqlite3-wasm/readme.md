# SQLite3 WASM Worker

Webブラウザ上で SQLite3 WASM を Web Worker から利用するためのシンプルなラッパーです。

SQLite のデータベースは OPFS（Origin Private File System）上に保存し、データベース処理を Web Worker に分離します。

ページ側では SQL を直接実行せず、`SqliteWorkerClient` を通して Worker に処理を依頼し、実際の SQL やテーブル定義はページごとのサブモジュールに記述する構成を想定しています。

## 構成

```text
sqlite/
├── sqlite3.mjs
├── sqlite3.wasm
├── sqlite-worker-client.js
├── sqlite-worker.js
├── sqlite-helper.js
└── sqlite-worker-sub-sample.js
```

### sqlite-worker-client.js

Webページ側から使用するクライアントクラスです。

`SqliteWorkerClient` を生成し、SQLite Worker の初期化やリクエスト送信を行います。

主な機能:

* Web Worker の生成
* SQLite Worker の初期化
* OPFS データベースの初期化
* ページ別テーブルの初期化
* Worker へのリクエスト送信
* Promise による結果受信
* `navigator.locks` を使用したページ単位の排他制御
* SQLite DB のバイナリエクスポート
* SQLite DB のバイナリインポート
* Worker の終了とロック解除

初期化は次の順序で行われます。

```text
Worker 起動
    ↓
initworker
    ↓
initdb
    ↓
inittables
    ↓
ready
```

`navigator.locks` を利用することで、同じページを複数タブで開いた場合の更新処理の競合を防ぐことができます。SELECT のみであればロックなしで利用することも想定しています。

### sqlite-worker.js

SQLite3 WASM を実際に動作させる Web Worker です。

`sqlite3.mjs` を読み込み、`sqlite3.oo1.OpfsDb` を使用して OPFS 上に SQLite データベースを作成します。

データベース名は `SqliteWorkerClient` に渡されたページ名から生成されます。

例えば、

```javascript
const sqliteClient = new SqliteWorkerClient("sample.php");
```

の場合、

```text
app.sample.sqlite3
```

というデータベースを使用します。

通常のDB処理は Worker 内へ直接追加するのではなく、ページごとのサブモジュールへ振り分けます。

```javascript
const sqlite_sub_modules = {
    sample: "./sqlite-worker-sub-sample.js"
};
```

Worker は対応するモジュールを動的に `import()` し、

```javascript
execute(db, type, data)
```

を呼び出します。

### sqlite-worker-sub-sample.js

ページ固有の SQLite 処理を記述するサンプルです。

各サブモジュールは、

```javascript
export function execute(db, type, data)
```

を公開する必要があります。

ファイル名は次の形式を想定しています。

```text
sqlite-worker-sub-<ページ名>.js
```

例えば、

```text
sample.php
```

用の処理であれば、

```text
sqlite-worker-sub-sample.js
```

を作成します。

`execute()` で `type` に応じて処理を振り分けます。

```javascript
export function execute(db, type, data)
{
    switch (type) {
        case "inittables":
            return initTables(db, data);

        default:
            throw new Error(
                `Unknown sample operation: ${type}`
            );
    }
}
```

### sqlite-helper.js

SQLite 操作用の補助クラスです。

以下のクラスを提供します。

```text
TableDefinition
ColumnDefinition
ColumnValues
SqliteHelper
```

`TableDefinition` と `ColumnDefinition` を利用してテーブル構造を定義できます。複合主キーの場合は `pKeyLevel` によって主キーの順序を指定できます。

例えば:

```javascript
const colDefs = [];

colDefs.push(
    new ColumnDefinition("INDEX", "INTEGER", 1)
);
colDefs.push(
    new ColumnDefinition("EMPCD", "TEXT")
);
colDefs.push(
    new ColumnDefinition("NAME", "TEXT")
);

const tableDefinitions = [
    new TableDefinition("employee", colDefs)
];

SqliteHelper.initTables(
    db,
    tableDefinitions
);
```

`SqliteHelper` には現在、テーブル作成・存在確認・SELECT・INSERT・DELETE などの基本処理があります。INSERT はトランザクションと Prepared Statement を使用して複数行を処理します。

## 使用方法

### 1. SQLite3 WASM の配置

SQLite 公式配布物から SQLite3 WASM を取得し、少なくとも以下を配置します。

```text
sqlite3.mjs
sqlite3.wasm
```

`sqlite-worker.js` から `sqlite3.mjs` を読み込みます。

```javascript
import sqlite3InitModule from "./sqlite3.mjs";
```

### 2. クライアントの生成

Webページ側で `sqlite-worker-client.js` を読み込みます。

その後、ページごとにクライアントを生成します。

```javascript
const sqliteClient =
    new SqliteWorkerClient("sample.php");
```

### 3. 初期化

更新処理を行う場合は排他ロックを有効にして初期化します。

```javascript
await sqliteClient.initAll(true);
```

SELECT のみで排他制御が不要な場合は、

```javascript
await sqliteClient.initAll(false);
```

とします。

データベースを一度削除して再作成したい場合は、コンストラクタの第2引数に `true` を指定します。

```javascript
const sqliteClient =
    new SqliteWorkerClient(
        "sample.php",
        true
    );

await sqliteClient.initAll(true);
```

初期化処理にはデフォルトで60秒のタイムアウトが設定されています。

## Workerへのリクエスト

ページ側から、

```javascript
sqliteClient.request(
    type,
    data
);
```

を呼び出します。

戻り値は Promise です。

例えば、

```javascript
try {
    const result = await sqliteClient.request(
        "find",
        {
            employeeCd: "1001"
        }
    );

    console.log(result);

} catch (error) {
    console.error(error);
}
```

リクエストには連番のIDが付与され、Workerから返されたIDと照合されます。また、1つの `SqliteWorkerClient` では同時に複数リクエストを送らない設計になっています。

処理の流れは、

```text
Web Page
   │
   │ request(type, data)
   ▼
SqliteWorkerClient
   │
   │ postMessage()
   ▼
sqlite-worker.js
   │
   │ import()
   ▼
sqlite-worker-sub-<page>.js
   │
   │ execute(db, type, data)
   ▼
SQLite3 WASM
   │
   ▼
OPFS
```

となります。

## ページ別モジュールの追加

例えば、

```text
employee.php
```

用のDB処理を追加する場合、

```text
sqlite-worker-sub-employee.js
```

を作成します。

`sqlite-worker.js` に登録します。

```javascript
const sqlite_sub_modules = {
    sample: "./sqlite-worker-sub-sample.js",
    employee: "./sqlite-worker-sub-employee.js"
};
```

サブモジュールでは、

```javascript
export function execute(db, type, data)
{
    switch (type) {
        case "inittables":
            return initTables(db, data);

        case "find":
            return find(db, data);

        case "save":
            return save(db, data);

        default:
            throw new Error(
                `Unknown operation: ${type}`
            );
    }
}
```

のように処理を振り分けます。

この構成により、Webページ側にSQLを記述せず、SQLite関連処理をWorker側へ集約できます。これは `sqlite-worker-client.js` の設計意図としても明記されています。

## 排他制御

更新を行うページでは、

```javascript
await sqliteClient.initAll(true);
```

として `navigator.locks` による排他ロックを取得します。

ロック名は、

```text
sqlite-worker-<ownerPage>
```

です。

同じブラウザで同一ページを複数タブから開いた場合、先に開いたページがロックを保持していれば、後から開いたページの初期化はエラーになります。

ページを終了する際などに、

```javascript
sqliteClient.terminate();
```

を呼び出すことで Worker を終了し、保持しているロックを解除できます。

## データベースのエクスポート

現在の SQLite データベースを `Uint8Array` として取得できます。

```javascript
const [success, bytes] =
    await sqliteClient.downloadDbBinary();

if (success) {
    // bytes は Uint8Array
}
```

Worker 側では `sqlite3_js_db_export()` を使用して SQLite DB をバイナリ化し、Transferable としてメインスレッドへ返しています。

## データベースのインポート

HTML の `input type="file"` などから取得した `File` は、

```javascript
const bytes =
    await sqliteClient.fileToUnit8Array(file);
```

で `Uint8Array` に変換できます。

その後、

```javascript
await sqliteClient.uploadDbBinary(bytes);
```

で OPFS 上のデータベースへインポートできます。

インポート時には Worker 側で一度DBを閉じ、`OpfsDb.importDb()` を実行してから再度DBを開きます。

## COOP / COEP

SQLite3 WASM の利用方法やブラウザ環境によっては、Cross-Origin Isolation が必要になります。

Webサーバー側で必要に応じて以下のHTTPヘッダーを設定してください。

```http
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Embedder-Policy: require-corp
```

利用する SQLite3 WASM のバージョンおよび構成に応じて、SQLite公式ドキュメントも確認してください。

## 対応ブラウザ

このコードでは主に以下のブラウザAPIを使用します。

```text
Web Worker
ES Modules
OPFS
navigator.storage
navigator.locks
Uint8Array / ArrayBuffer
```

そのため、これらのAPIをサポートする比較的新しいブラウザを対象としています。

特に OPFS および `navigator.locks` の対応状況には注意してください。

## 注意事項

このコードは SQLite3 WASM を利用するための簡易ラッパーであり、汎用ORMや完全なSQLite抽象化ライブラリを目的としたものではありません。

ページごとに SQLite DB を分離し、

```text
Webページ
    ↓
SqliteWorkerClient
    ↓
SQLite Worker
    ↓
ページ別サブモジュール
    ↓
SQLite3 WASM / OPFS
```

という構成で利用することを想定しています。

また、SQLite3 WASM 本体（`sqlite3.mjs`、`sqlite3.wasm` など）のライセンス・配布条件については SQLite 公式の情報を確認してください。

## License

```text
MIT License
```

SQLite および SQLite3 WASM は、それぞれのライセンス・利用条件に従います。

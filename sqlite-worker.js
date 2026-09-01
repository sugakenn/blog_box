/**
 * このファイルは、sqlite-worker-clientから読み込まれ、直接読み込まない
 * Sqlite3-wasmのworker
 * このWorker更新時は、sqlite-worker-client.jsのバージョン番号も更新すること
 * 
 */

/**
 * Sqlite3-wasmのworkerは単一にする
 * メッセージは、シリアライズされて処理されるので、SELECTだけなら複数のタブから同時にアクセスしても問題ない
 * ただし、複数のタブから同じテーブルに対して更新を行うと、意図しない結果になる可能性がある
 * sqlite-worker-client.js側でページ単位でロックをかける
 * 
 * db名はsqlite-worker-clientのコンストラクタのownerPageを使って、ページ単位で分ける
 * またこれにより下流の処理も分岐させる
 */

/**
 * ページ毎のサブモジュールの定義
 * サブモジュールはexecute(db, type, data)をexportする必要がある
 */

const sqlite_sub_modules = {
    sample: "./sqlite-worker-sub-sample.js"
};

// import時./はファイルのある場所を基準にする
import sqlite3InitModule from "./sqlite3.mjs";

//初期化されるまでブロック
const sqlite3 = await sqlite3InitModule();

let db = null;

self.onmessage = async event => {


    const {
        id,
        ownerPage,
        type,
        data
    } = event.data;

    try {
        if (!ownerPage) {
            //想定外
            throw new Error(
                `オーナーページが設定されていません(想定外)`
            );
        }
        
        const ownerPageWithoutExt = ownerPage.replace(/\.[^.]+$/, "");
        
        //データベースのイニシャライズ(テーブル定義はページ毎に別処理:inittables)
        if (type === "initdb") {
            const dbName = `app.${ownerPageWithoutExt}.sqlite3`;

            if (data?.reset) {
                const root = await navigator.storage.getDirectory();

                try {
                    await root.removeEntry(dbName);
                } catch (error) {
                    if (error.name !== "NotFoundError") {
                        throw error;
                    }
                }
            }

            db = new sqlite3.oo1.OpfsDb(
                `/${dbName}`,
                "c"
            );

            self.postMessage({
                id,
                success: true
            });

            return;
        } 

        //import時は閉じた状態でないといけない
        if (type==="import") {
            const dbName = `app.${ownerPageWithoutExt}.sqlite3`;

            // 一旦DBを閉じる
            if (db) {
                db.close();
                db = null;
            }
            
            db = await sqlite3.oo1.OpfsDb.importDb(
                `/${dbName}`,
                data
            );

            // オープン
            db = new sqlite3.oo1.OpfsDb(
                `/${dbName}`,
                "c"
            );
            self.postMessage({
                id,
                success: true
            });
            return;
        }


        if (!db) {
            throw new Error("DBが初期化されていません");
        }

        if (type === "export") {
            // そのままエクスポート
            const bytes = sqlite3.capi.sqlite3_js_db_export(db.pointer);
            self.postMessage(
                {
                    id,
                    success: true,
                    data: bytes
                },
                [bytes.buffer]
            );
            return;
        }



        //各実装へ
        const moduleUrl = sqlite_sub_modules[ownerPageWithoutExt];

        if (!moduleUrl) {
            console.error(`ページ毎のDB処理定義がありません: ${ownerPageWithoutExt}`);
            throw new Error(
                `ページ毎のDB処理定義がありません: ${ownerPageWithoutExt}`
            );
        }

        const module = await import(moduleUrl);

        if (typeof module.execute !== "function") {
            console.error(`execute() is not defined in ${moduleUrl}`);
            throw new Error(
                `execute() is not defined: ${ownerPageWithoutExt}`
            );
        }

        const result = await module.execute(
            db,
            type,
            data
        );

        self.postMessage({
            id : id,
            success: true,
            data: result
        });

    } catch (error) {
        self.postMessage({
            id : id,
            success: false,
            error: error.message
        });
    }
};

//SQLite3の読み込み終了とWORKER待機開始を通知する 
self.postMessage({
    id: "initworker",
    success: true,
    message: "sqlite-worker.js is ready"
});

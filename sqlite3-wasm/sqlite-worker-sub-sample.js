/**
 * このファイルは、sqlite-workerからimportされるサブモジュール のサンプル
 * 
 * sqlite-worker-sub-<ページ名>.jsの形式で、ページ毎に作成する
 * 
 * sqlite-worker-sub-sample.js
 * Sqlite3-wasmのworker版のサンプル
 * サンプルのサブモジュール
 * このモジュールを更新する時は sqlite-worker.jsのバージョン番号も更新すること
 * 
 * 
 * @param {*} db 
 * @param {*} type 
 * @param {*} data 
 * @returns 
 */
import * as helper from './sqlite-helper.js';

//名前付きエクスポートにしておく
export function execute(db, type, data)
{
    switch (type) {

        case "inittables":
            return initTables(db,data);
        default:
            throw new Error(
                `Unknown sample operation: ${type}`
            );
    }
}

/**
 * テーブル作成
 * @param {*} db 
 * @returns 
 */
function initTables(db, data)
{
    const colDefs = [];

    //既存時にクリアするか
    const dropIfExists = ()=> {
        if (data?.reset) {
            return true;
        } else {
            return false;
        }
    }

    //カラム定義
    colDefs.push(new helper.ColumnDefinition("INDEX", "INTEGER",1));
    colDefs.push(new helper.ColumnDefinition("EMPCD", "TEXT"));
    colDefs.push(new helper.ColumnDefinition("NAME", "TEXT"));
    
    //テーブル定義
    const tableDefinitions = [
        new helper.TableDefinition("employee", colDefs)
    ];

    //console.log("initTables: tableDefinitions", tableDefinitions);
    
    return helper.SqliteHelper.initTables(db, tableDefinitions, dropIfExists);
}

function find(db, data)
{
    const rows = [];

    db.exec({
        sql: `
            SELECT
                employee_cd,
                employee_name
            FROM employee
            WHERE employee_cd = ?
        `,
        bind: [
            data.employeeCd
        ],
        rowMode: "object",
        callback: row => {
            rows.push(row);
        }
    });

    return rows;
}

function save(db, data)
{
    db.exec({
        sql: `
            INSERT INTO employee (
                employee_cd,
                employee_name
            )
            VALUES (?, ?)
        `,
        bind: [
            data.employeeCd,
            data.employeeName
        ]
    });

    return true;
}

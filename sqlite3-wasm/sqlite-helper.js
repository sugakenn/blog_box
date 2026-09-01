/**
 * JS helper class for sqlite
 */


export class TableDefinition
{
    /**
     * テーブル定義クラス
     * @param {string} name テーブル名
     * @param {array<ColumnDefinition>} columnDefinitions ColumnDefinitionの配列
     * 
     */
    constructor(name, columnDefinitions) {
        this.name = name;
        this.columns = [...columnDefinitions];//この後のソートでかわってしまうので、配列コピー、配列は使い捨て中のクラスは共有
      
        //テーブル定義からPKEYを取得
        this.pKeys = columnDefinitions
            .filter(col => col.pKeyLevel > 0)
            .sort((a, b) => a.pKeyLevel - b.pKeyLevel)
            .map(col => col.name);
    }
}

export class ColumnDefinition
{
    /**
     * カラム定義クラス
     * @param {string} name カラム名
     * @param {string} type カラム型
     * @param {number} pKeyLevel 主キーのレベル（0は主キーではない）1以上の値を指定することで、複合主キーの順序を定義できる
     */
    constructor(name, type, pKeyLevel = 0) {   
        this.name = name;
        this.type = type;
        this.pKeyLevel = pKeyLevel;
    }
}

export class ColumnValues 
{
    /**
     * カラム値クラス
     * @param {string} colName 
     * @param {*} value 
     */
    constructor(colName, value) {
        this.name = colName;
        this.value = value;
    }
}

/**
 * ヘルパー本体
 */
export class SqliteHelper
{
    /**
     * 
     * @param {*} db 
     * @param {*} tableDefinitions 
     * @param {*} dropIfExists 
     */
    static initTables(db, tableDefinitions,dropIfExists = false)
    {
        if (tableDefinitions === null || Array.isArray(tableDefinitions) === false || tableDefinitions.length === 0) {
            console.error("tableDefinitions is null or not an array or empty");
            return false
        }

        for (const tableDef of tableDefinitions) {

            if (!tableDef || !tableDef.name) {
                console.error("Invalid table definition:", tableDef);
                return false;
            }

            let isEixsts = SqliteHelper.isTableExists(db, tableDef.name);
            
            //nullの時はチェックSQLの実行に失敗しているので、テーブル作成を中止する
            if (isEixsts === null) {
                console.error(`Failed to check if table ${tableDef.name} exists`);
                return false;
            }

            if (dropIfExists) {
                try {
                    db.exec({
                        sql: `DROP TABLE IF EXISTS ${tableDef.name}`
                    });

                    if (SqliteHelper.createTable(db, tableDef)===false) {
                        return false;
                    }
                } catch (error) {
                    console.error(`Failed to drop table ${tableDef.name}:`, error);
                    return false;
                }
            } else {
                if (isEixsts) {
                    continue;
                } else {
                    if (SqliteHelper.createTable(db, tableDef)===false) {
                        return false;
                    }
                }
            }
        }
        
        return true;        
    }

    /**
     * テーブル作成
     */
    static createTable(db, tableDef)
    {
        const columnDefs = tableDef.columns.map(col => `"${col.name}" ${col.type}`).join(", ");
        const pKeyDefs = tableDef.pKeys.length > 0 ? `, PRIMARY KEY (${tableDef.pKeys.map(col => `"${col}"`).join(", ")})` : "";
        const sql = `CREATE TABLE IF NOT EXISTS "${tableDef.name}" (${columnDefs}${pKeyDefs})`;
        try {
            //console.log(`Creating table ${tableDef.name} with SQL: ${sql}`);
            db.exec({ sql });
            return true;
        } catch (error) {
            console.error(`Failed to create table ${tableDef.name}:`, error);
            return false;
        }
    }
    
    /**
     * テーブルが存在するか
     * @param {sqlite} db 
     * @param {string} tableName 
     * @returns 
     */
    static isTableExists(db, tableName)
    {
        try {
            const result = db.exec({
                sql: `
                    SELECT name
                    FROM sqlite_master
                    WHERE type='table' AND name=?
                `,
                bind: [tableName],
                rowMode: "object"
            });
            return result.length > 0;
        } catch (error) {
            console.error(`Failed to check if table ${tableName} exists:`, error);
            return null;
        }
    }

    static select(db, tableDef, whereKeyValues = [])
    {
        const tableName = tableDef.name;
        const whereClause = whereKeyValues.length > 0 ? "WHERE " + whereKeyValues.map(kv => `"${kv.name}" = ?`).join(" AND ") : "";
        const bindParams = whereKeyValues.map(kv => kv.value);
        const sql = `SELECT * FROM "${tableName}" ${whereClause}`;
        
        try {
            return db.exec({
                sql,
                bind: bindParams,
                rowMode: "object"
            });
        } catch (error) {
            console.error(`Failed to select from table ${tableName}:`, error);
            return null;
        }
    }

    /**
     * INSERT
     * @param {sqlite} db
     * @param {TableDefinition} tableDef
     * @param {array<array<ColumnValues>>} rows
     * @param {array<string>} columns
     * @returns
     */
    static insert(db, tableDef, rows, columns = null)
    {
        const tableName = tableDef.name;

        if (columns === null) {
            columns = tableDef.columns.map(col => col.name);
        }

        const columnNames = columns.map(col => `"${col}"`).join(", ");
        const placeholders = "(" + columns.map(() => "?").join(", ") + ")";

        const sql =
            `INSERT INTO "${tableName}" (${columnNames}) VALUES ${placeholders}`;

        let stmt = null;

        try {
            db.exec("BEGIN");

            stmt = db.prepare(sql);

            for (const row of rows) {
                const bindParams =
                    columns.map(colName => row[colName]);

                stmt.bind(bindParams);
                stmt.step();
                stmt.reset();
            }

            db.exec("COMMIT");

            return true;
        } catch (e) {
            try {
                db.exec("ROLLBACK");
            } catch (rollbackError) {
                console.error("Failed to rollback transaction:", rollbackError);
                return false;
            }

            return false;
        } finally {
            if (stmt !== null) {
                stmt.finalize();
            }
        }
    }

    static delete(db, tableDef, whereKeyValues)
    {
        const tableName = tableDef.name;
        const whereClause = whereKeyValues.length > 0 ? "WHERE " + whereKeyValues.map(kv => `"${kv.name}" = ?`).join(" AND ") : "";
        const bindParams = whereKeyValues.map(kv => kv.value);
        const sql = `DELETE FROM "${tableName}" ${whereClause}`;
        
        try {
            db.exec({
                sql,
                bind: bindParams
            });
            return true;
        } catch (error) {
            console.error(`Failed to delete from table ${tableName}:`, error);
            return false;
        }
    }
}

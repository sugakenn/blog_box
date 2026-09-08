<?php

/**
 * WireGuard 用のビーコンサーバー
 * parameter:
 * key: APIキー
 * user: ユーザーID
 * type: 処理種別(set, show, csv, sample)
 * 
 * wg-beacon.jsonにパラメータを設定しておき別のスクリプトでwg-quickで設定する
 * 
 */
 
declare(strict_types=1);

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// ロケール
setlocale(LC_ALL, 'ja_JP.UTF-8');

const API_KEY  = 'YOUR_SECRET_API_KEY';
const DATA_DIR = __DIR__ . '/data';
const LOG_FILE  = '/var/log/apache2/wg-beacon.log';
const SAVE_FILE = DATA_DIR . '/wg-beacon.json';

const ROOT_PUB_KEY="YOUR_WG_ROOT_SV_PUBKEY";
const ROOT_PORT="YOUR_WG_ROOT_SV_WAIT_PORT";

//endpointは可変
//portに対してポートフォワードを設定しておくこと
//active は "active"の場合のみセットする
const PARAM_KEYS = [
    "name",
    "pubkey",
    "endpoint",
    "port",
    "updated_at",
    "tunnel_ip",
    "active"
];
//json は　id => PARAM_KEYS の連想配列で保存する



// --------------------------------------------------
// dataディレクトリ初期化
// --------------------------------------------------
function initDataDir(): bool
{
    // dataディレクトリ作成
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0750, true)) {
            return false;
        }
    }

    // .htaccess作成
    $htaccess = DATA_DIR . '/.htaccess';

    if (!is_file($htaccess)) {
        $contents = <<<HTACCESS
Require all denied
HTACCESS;

        if (file_put_contents($htaccess, $contents, LOCK_EX) === false) {
            return false;
        }
    }

    // JSONファイルがなければ作成
    if (!is_file(SAVE_FILE)) {
        if (file_put_contents(SAVE_FILE, "{}\n", LOCK_EX) === false) {
            return false;
        }
    }

    return true;
}


// --------------------------------------------------
// Beacon登録
// --------------------------------------------------
function setBeacon(string $user, string $remoteIp): void
{
    $fp = fopen(SAVE_FILE, 'c+');

    if ($fp === false) {
        http_response_code(500);
        exit;
    }

    try {
        // 読み込み～書き込みまで排他 ロックを待ち受ける
        if (!flock($fp, LOCK_EX)) {
            http_response_code(500);
            exit;
        }

        rewind($fp);

        $json = stream_get_contents($fp);

        $data = [];

        if ($json !== false && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                http_response_code(500);
                exit;
            }
        } else {
            http_response_code(500);
            exit;
        }

        //setではendpointと更新日時のみを保存する
        $data[$user]['endpoint']= $remoteIp;
        $data[$user]['updated_at'] = date('Y-m-d H:i:s');

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            http_response_code(500);
            exit;
        }

        rewind($fp);
        ftruncate($fp, 0);

        if (fwrite($fp, $json . PHP_EOL) === false) {
            http_response_code(500);
            exit;
        }

        fflush($fp);

    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    http_response_code(200);
}

// --------------------------------------------------
// Beacon一覧CSV出力
// --------------------------------------------------
function csvBeacon(): void
{
    if (!is_file(SAVE_FILE)) {
        http_response_code(404);
        exit;
    }

    $fp = fopen(SAVE_FILE, 'r');

    if ($fp === false) {
        http_response_code(500);
        exit;
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            http_response_code(500);
            exit;
        }

        $json = stream_get_contents($fp);

    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    $data = json_decode($json ?: '{}', true);

    if (!is_array($data)) {
        http_response_code(500);
        exit;
    }

    // CSVとして返す
    header('Content-Type: text/csv; charset=UTF-8');
    //header('Content-Disposition: attachment; filename="beacon.csv"');
    
    $out = fopen('php://output', 'w');

    if ($out === false) {
        http_response_code(500);
        exit;
    }

    // ヘッダー
    fputcsv($out, array_merge(['id'], PARAM_KEYS));

    // データ
    foreach ($data as $user => $item) {
        $param=[];
        $param['id'] = $user;
        foreach(PARAM_KEYS as $key){
            $param[$key] = $item[$key] ?? '';
        }

        fputcsv($out, $param);
    }

    fclose($out);
    exit;
}

function makeSampleJson(): void
{
    $sample = [
        'sample_user' => [
            'name' => 'Sample User',
            'pubkey' => 'YOUR_PUBLIC_KEY',
            'endpoint' => 'example.com',
            'port' => 51820,
            'updated_at' => date('Y-m-d H:i:s'),
            'tunnel_ip' => '172.30.255.0/32',
            'active' => 'active'
        ]
    ];

    $str =  json_encode(
        $sample,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($str === false) {
        http_response_code(500);
        exit;
    }

    if (file_put_contents(SAVE_FILE.".sample", $str . PHP_EOL, LOCK_EX) === false) {
        http_response_code(500);
        exit;
    }

    exit;
}
            
// --------------------------------------------------
// Beacon一覧表示
// --------------------------------------------------
function showBeacon(): void
{
    $fp = fopen(SAVE_FILE, 'r');

    if ($fp === false) {
        http_response_code(500);
        exit;
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            http_response_code(500);
            exit;
        }

        $json = stream_get_contents($fp);

    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    $data = json_decode($json ?: '{}', true);

    if (!is_array($data)) {
        http_response_code(500);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');

    echo '<!DOCTYPE html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>WG端末リスト</title>';

    //ヒアドキュメント開始
    echo <<<HTML
<style>
body {
    font-family: sans-serif;
    margin: 20px;
}

table {
    border-collapse: collapse;
    min-width: 600px;
}

th,
td {
    border: 1px solid #ccc;
    padding: 6px 10px;
    text-align: left;
}

th {
    background: #eee;
}
</style>
HTML;
//ヒアドキュメント終了

    echo '</head>';
    echo '<body>';

    echo '<h1>WG端末リスト</h1>';
    echo '<p>中央SV PUBKEY'.ROOT_PUBKEY.' PORT:'.ROOT_POOT.'</p>';

    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    echo '<th>id</th>';
    foreach (PARAM_KEYS as $key) {
        echo '<th>' . htmlspecialchars(
            (string)$key,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) . '</th>';
    }
    echo '</tr>';
    echo '</thead>';

    echo '<tbody>';

    foreach ($data as $user => $item) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars(
            (string)$user,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) . '</td>';
        foreach (PARAM_KEYS as $key) {
            $value = $item[$key] ?? '';
            echo '<td>' . htmlspecialchars(
                (string)$value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '</body>';
    echo '</html>';

    exit;
}

// --------------------------------------------------
// ログ出力
// --------------------------------------------------
function writeLog(string $message, bool $isError = true): void
{
    $err = $isError ? 'ERROR' : 'INFO';
    $date = date('Y-m-d H:i:s');
    $logMessage = "[$err] [$date] $message\n";
    if (!error_log($logMessage, 3, LOG_FILE)) {
        error_log($logMessage, 0);
    }
}


// ==================================================
// メイン処理
// ==================================================

// dataディレクトリ準備
if (!initDataDir()) {
    http_response_code(500);
    exit;
}

// 接続元IP
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

if ($remoteIp === '') {
    http_response_code(400);
    exit;
}

// POST
$type   = $_REQUEST['type'] ?? '';
$user   = $_REQUEST['user'] ?? ('unknown_' . $remoteIp);
$apiKey = $_REQUEST['key'] ?? '';

// APIキー不一致
if (!hash_equals(API_KEY, $apiKey)) {
    http_response_code(404);
    exit;
}

// 処理振り分け
switch ($type) {
    case 'set':
        setBeacon($user, $remoteIp);
        break;
    case 'show':
        showBeacon();
        break;
    case 'csv':
        csvBeacon();
        break;
    case 'sample':
        //設定サンプルを出力する
        makeSampleJson();
        break;
    default:
        http_response_code(400);
        break;
}

exit;

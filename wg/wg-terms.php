<?php

/**
 * WireGuard 用のビーコンサーバー
 * parameter:
 * type: 処理種別(register(登録実行),edit(編集実行), show, csv, sample)
 * 
 * wg-term.jsonにパラメータを設定しておき別のスクリプトでwg-quickで設定する
 * Double submit CookieパターンのCSRF対策を実装しているので、SSLでアクセスすること
 * 
 */
 
declare(strict_types=1);

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// ロケール
setlocale(LC_ALL, 'ja_JP.UTF-8');

const DATA_DIR = __DIR__ . '/data';
const LOG_FILE  = '/var/log/apache2/wg-term-setting.log';
const SAVE_FILE = DATA_DIR . '/wg-term.json';

const ROOT_PUB_KEY="中央サーバーの公開鍵";
const ROOT_IP="192.168.255.1";
const ROOT_PORT="51820";

//endpointは可変
//portに対してポートフォワードを設定しておくこと
//active は "active"の場合のみセットする
const PARAM_KEYS = [
    "name",
    "pubkey",
    "endpoint",
    "port",
    "tunnel_ip",
    "active",
    "inserted_at",
    "last_connected_at"
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
// 端末登録
// --------------------------------------------------
function registerTerm(): array
{
    $param = [
        'name' => $_POST['name'] ?? '',
        'pubkey' => $_POST['pubkey'] ?? '',
        'endpoint' => $_POST['endpoint'] ?? '',
        'port' => $_POST['port'] ?? '',
        'tunnel_ip' => $_POST['tunnel_ip'] ?? '',
        'active' => $_POST['active'] ?? ''
    ];

    $pubkey = $param['pubkey'];

    if (isset($_POST['delete']) && $_POST['delete'] === '1') {
        //削除処理
        $fp = fopen(SAVE_FILE, 'c+');
        //オープンできない時はfinallyでfcloseできないので、ここでreturnする
        if ($fp === false) {
            return [false,"端末設定ファイルの読み込みに失敗しました",$param];
        }

        try {   
           

            if (!flock($fp, LOCK_EX)) {
                return [false,"端末設定ファイルのロックに失敗しました",$param];
            }

            rewind($fp);

            $json = stream_get_contents($fp);

            $data = [];

            if ($json !== false && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                } else {
                    return [false,"JSONのデコードに失敗しました",$param];
                }
            } else {
                return [false,"JSONの読み込みに失敗しました",$param];
            }

            if (isset($data[$pubkey])) {
                unset($data[$pubkey]);
            } else {
                return [false,"指定された公開鍵のデータが見つかりません",$param];
            }

            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            if ($json === false) {
                return [false,"JSONのエンコードに失敗しました",$param];
            }

            rewind($fp);
            ftruncate($fp, 0);

            if (fwrite($fp, $json . PHP_EOL) === false) {
                return [false,"JSONの書き込みに失敗しました",$param];
            }

            fflush($fp);

            return [true,"削除に成功しました",$param];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    } else {

        //入力値チェック
        $errmsg = validateTermParam($param);
        if ($errmsg !== '') {
            return [false, $errmsg, $param];
        }

        $fp = fopen(SAVE_FILE, 'c+');
        //オープンできない時はfinallyでfcloseできないので、ここでreturnする
        if ($fp === false) {
            return [false,"端末設定ファイルの読み込みに失敗しました",$param];
        }

        try {


            // 読み込み～書き込みまで排他 ロックを待ち受ける
            if (!flock($fp, LOCK_EX)) {
                return [false,"端末設定ファイルのロックに失敗しました",$param];
            }

            rewind($fp);

            $json = stream_get_contents($fp);

            $data = [];

            if ($json !== false && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                } else {
                    return [false,"JSONのデコードに失敗しました",$param];
                }
            } else {
                return [false,"JSONの読み込みに失敗しました",$param];
            }

            //setではendpointと更新日時のみを保存する
            $blnNew = false;
            if (!isset($data[$pubkey])) {
                $data[$pubkey] = [];
                $blnNew = true; 
            }

            $data[$pubkey]['name'] = $param['name'];
            $data[$pubkey]['pubkey'] = $param['pubkey'];
            $data[$pubkey]['endpoint'] = $param['endpoint'];
            $data[$pubkey]['port'] = $param['port'];
            $data[$pubkey]['tunnel_ip'] = $param['tunnel_ip'];
            $data[$pubkey]['active'] = $param['active'];

            if ($blnNew) {
                $data[$pubkey]['inserted_at'] = date('Y-m-d H:i:s');
                $data[$pubkey]['last_connected_at'] = "none";
            }

            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            if ($json === false) {
                return [false,"JSONのエンコードに失敗しました",$param];
            }

            rewind($fp);
            ftruncate($fp, 0);

            if (fwrite($fp, $json . PHP_EOL) === false) {
                return [false,"JSONの書き込みに失敗しました",$param];
            }

            fflush($fp);

            return [true,"登録に成功しました",$param];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

// --------------------------------------------------
// 端末パラメータのバリデーション
// --------------------------------------------------
function validateTermParam(array $param): string
{
    // 名前
    $name = trim((string)($param['name'] ?? ''));

    if ($name === '') {
        return '名前を入力してください';
    }

    if (mb_strlen($name, 'UTF-8') > 30) {
        return '名前は30文字以内で入力してください';
    }


    // WireGuard 公開鍵
    $pubkey = trim((string)($param['pubkey'] ?? ''));

    if ($pubkey === '') {
        return '公開鍵を入力してください';
    }

    // WireGuard公開鍵は32byteをBase64化したもの
    $decoded = base64_decode($pubkey, true);

    if (
        $decoded === false ||
        strlen($decoded) !== 32 ||
        strlen($pubkey) !== 44
    ) {
        return '公開鍵の形式が正しくありません';
    }

    // Endpoint
    $endpoint = trim((string)($param['endpoint'] ?? ''));

    // Endpointは空白を許可
    if ($endpoint !== '') {
        if (strlen($endpoint) > 253) {
            return 'エンドポイントが長すぎます';
        }

        // ホスト名またはIPアドレス
        if (
            filter_var($endpoint, FILTER_VALIDATE_IP) === false &&
            filter_var($endpoint, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            return 'エンドポイントの形式が正しくありません';
        }
    }


    // ポート番号
    $port = trim((string)($param['port'] ?? ''));

    // Endpointと同様に空白を許可
    if ($port !== '') {
        if (
            filter_var(
                $port,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                        'max_range' => 65535,
                    ]
                ]
            ) === false
        ) {
            return 'ポート番号は1～65535で入力してください';
        }
    }


    // トンネルIP
    $tunnelIp = trim((string)($param['tunnel_ip'] ?? ''));

    if ($tunnelIp === '') {
        return 'トンネルIPを入力してください';
    }

    // 今回はIPv4/32のみ許可
    if (!str_ends_with($tunnelIp, '/32')) {
        return 'トンネルIPはIPv4/32で指定してください';
    }

    $ip = substr($tunnelIp, 0, -3);

    if (
        filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4
        ) === false
    ) {
        return 'トンネルIPの形式が正しくありません';
    }


    // active
    $active = (string)($param['active'] ?? '');

    if (!in_array($active, ['active', 'sleep'], true)) {
        return '有効状態の指定が正しくありません';
    }


    return '';
}

// --------------------------------------------------
// Term一覧CSV出力
// --------------------------------------------------
function csvWgTerm(): void
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
    //header('Content-Disposition: attachment; filename="WgTerm.csv"');
    
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


// --------------------------------------------------
// WireGuardの最新接続時刻を更新する
// sudo権限でwgコマンドを実行できるようにしておくこと
// www-data ALL=(root) NOPASSWD: /usr/bin/wg show wg0 dump
// --------------------------------------------------
function updateLastConnectedAt(): bool
{
    // コマンドは固定する
    $cmd = 'sudo /usr/bin/wg show wg0 dump';

    $output = [];
    $resultCode = 0;

    exec($cmd, $output, $resultCode);

    if ($resultCode !== 0) {
        writeLog("wg show wg0 dump の実行に失敗しました");
        return false;
    }

    /*
     * wg show wg0 dump
     *
     * 1行目: interface情報
     * 2行目以降:
     * public-key
     * preshared-key
     * endpoint
     * allowed-ips
     * latest-handshake
     * transfer-rx
     * transfer-tx
     * persistent-keepalive
     */

    $handshakes = [];

    foreach ($output as $index => $line) {

        // 1行目はinterface情報なので除外
        if ($index === 0) {
            continue;
        }

        $fields = explode("\t", $line);

        if (count($fields) < 5) {
            continue;
        }

        $pubkey    = $fields[0];
        $allowedIps = $fields[3];
        $handshake = $fields[4];

        // handshake=0 は一度も接続していない
        if (
            $pubkey !== '' &&
            ctype_digit($handshake) 
        ) {
            $handshakes[$pubkey]['timestamp'] = (int)$handshake;
            $handshakes[$pubkey]['allowed_ips'] = $allowedIps;
        }
    }


    // JSONを更新
    $fp = fopen(SAVE_FILE, 'c+');

    if ($fp === false) {
        writeLog("端末設定ファイルを開けませんでした");
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            writeLog("端末設定ファイルをロックできませんでした");
            return false;
        }

        rewind($fp);

        $json = stream_get_contents($fp);

        if ($json === false || $json === '') {
            writeLog("端末設定ファイルを読み込めませんでした");
            return false;
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            writeLog("端末設定JSONのデコードに失敗しました");
            return false;
        }


        // 公開鍵が一致する端末を更新
        foreach ($handshakes as $pubkey => $info) {

            if (!isset($data[$pubkey])) {
                //存在しない場合は新規生成
                $data[$pubkey] = [
                    'name' => '',
                    'pubkey' => $pubkey,
                    'endpoint' => '',
                    'port' => '',
                    'tunnel_ip' => $info['allowed_ips'] ?? '',
                    'active' => 'active',
                    'inserted_at' => date('Y-m-d H:i:s'),

                    'last_connected_at' => $info['timestamp'] > 0 ? date('Y-m-d H:i:s', $info['timestamp']) : 'none',
                ];
            } else {
                if ($info['timestamp'] > 0) {
                    $data[$pubkey]['last_connected_at']
                        = date('Y-m-d H:i:s', $info['timestamp']);
                }
            }
        }


        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            writeLog("端末設定JSONのエンコードに失敗しました");
            return false;
        }

        rewind($fp);

        if (!ftruncate($fp, 0)) {
            writeLog("端末設定ファイルをtruncateできませんでした");
            return false;
        }

        if (fwrite($fp, $json . PHP_EOL) === false) {
            writeLog("端末設定ファイルを書き込めませんでした");
            return false;
        }

        fflush($fp);

        return true;

    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
            
function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function editWgTerm(string $errmsg="",?array $prevData=null): void
{
    $pubkey = $_GET['pubkey'] ?? '';

    //$pubkeyが空の場合は新規
    $item = [
        'name' => '',
        'pubkey' => '',
        'endpoint' => '',
        'port' => '',
        'tunnel_ip' => '',
        'active' => '',
    ];

    $blnErr = false;

    if ($prevData) {
        // 前回の入力内容を保持するために$itemを上書き
        $blnErr = true;
        foreach (['name','pubkey','endpoint','port','tunnel_ip','active'] as $k) {
            if (isset($prevData[$k])) {
                $item[$k] = $prevData[$k];
            }
        }
    } else {
        while(true) {
            if ($pubkey==='') {
                //新規の時はlast_connected_atとinserted_atを追加
                $item['inserted_at'] = '';
                $item['last_connected_at'] = '';
            } else {
                $fp = fopen(SAVE_FILE, 'r');
                if ($fp === false) {
                    $blnErr = true;
                    $errmsg = "ファイルの読み込みに失敗しました";
                    break;
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
                if (!$json) {
                    $blnErr = true;
                    $errmsg = "JSONの読み込みに失敗しました";
                    break;
                }

                $rows = json_decode($json, true);
                if (is_array($rows)) {
                    $item = $rows[$pubkey] ?? null;
                    if ($item === null) {
                        $blnErr = true;
                        $errmsg = "指定された公開鍵のデータが見つかりません";
                        break;
                    }
                } else {
                    $blnErr = true;
                    $errmsg = "JSONのデコードに失敗しました";
                    break;
                }
            }
            break; // 正常終了
        }
    }

    header('Content-Type: text/html; charset=UTF-8');

    
    $title  = ($pubkey === '') ? '新規登録' : '編集';

    echo '<!DOCTYPE html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>WG端末 ' . $title . '</title>';
    echo <<<HTML
<style>
body { font-family: sans-serif; margin: 20px; }
table { border-collapse: collapse; min-width: 400px; }
th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
th { background: #eee; width: 160px; }
input[type=text], input[type=number] { width: 100%; box-sizing: border-box; }
.btn { margin-top: 12px; padding: 6px 20px; font-size: 1em; cursor: pointer; }
.error { color: red; margin-bottom: 10px; }
</style>
HTML;
    echo '</head>';
    echo '<body>';
    echo '<h1>WG端末 ' . $title . '</h1>';

    if ($blnErr) {
        echo '<p class="error">' . h($errmsg) . '</p>';
    } else {
        //SSLでアクセスしているか確認
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            echo '<p class="error">このページはSSLでアクセスしてください（そのままだと更新できません）</p>';
        }  
    }


    $csrfToken = getCsrfToken();

    echo '<form method="post" action="' . h($_SERVER['SCRIPT_NAME']) . '">';
    echo '<input type="hidden" name="type" value="register">';
    echo '<input type="hidden" name="csrf_token" value="' . h($csrfToken) . '">';
    
    echo '<table>';

    $fields = [
        'name'      => ['label' => '名前',           'type' => 'text',   'required' => true],
        'pubkey'    => ['label' => '公開鍵',         'type' => 'text',   'required' => true],
        'endpoint'  => ['label' => 'エンドポイント(空白可)', 'type' => 'text',   'required' => false],
        'port'      => ['label' => 'ポート番号(ex:51820)',     'type' => 'number', 'required' => false],
        'tunnel_ip' => ['label' => 'トンネルIP(ex:192.168.255.3/32)',     'type' => 'text',   'required' => true],
    ];

    foreach ($fields as $name => $meta) {
        $value = $item[$name] ?? '';
        $req   = $meta['required'] ? ' required' : '';
        $ro    = ($name === 'pubkey' && $pubkey !== '') ? ' readonly' : '';
        echo '<tr>';
        echo '<th><label for="f_' . $name . '">' . h($meta['label']) . ($meta['required'] ? ' *' : '') . '</label></th>';
        echo '<td><input type="' . $meta['type'] . '" id="f_' . $name . '" name="' . $name
            . '" value="' . h((string)$value) . '"' . $req . $ro . '></td>';
        echo '</tr>';
    }

    $blnActive = h((string)($item['active'] ?? ''))=== 'active';

    echo '<tr>';
    echo '<th><label for="f_active">有効</label></th>';
    echo '<td><select id="f_active" name="active">';
    echo '<option value="active"' . ($blnActive ? ' selected' : '') . '>active</option>';
    echo '<option value="sleep"' . (!$blnActive ? ' selected' : '') . '>sleep</option>';
    echo '</select></td>';
    echo '</tr>';

    echo '</table>';
    echo '<button type="submit" class="btn" id="f_save">保存</button>';
    if ($pubkey !== '') {
        echo '<input type="checkbox" id="f_delete" name="delete" value="1" onclick="changeButton(this.checked);"> <label for="f_delete">削除する</label>';
    }

    echo <<<HTML
<script>
function changeButton(checked) {
    if (checked) {
        document.getElementById('f_save').textContent = '削除';
    } else {
        document.getElementById('f_save').textContent = '保存';
    }
}
</script>
HTML;
    
    echo ' <a href="' . h($_SERVER['SCRIPT_NAME']) . '?type=show">一覧へ戻る</a>';
    echo '</form>';
    echo '</body>';
    echo '</html>';

    exit;
}

//Double submit CookieパターンのCSRF対策
//セッションを使わずに、Cookieとフォームの両方に同じトークンを持たせて検証する
function getCsrfToken(): string
{
    $token = $_COOKIE['csrf_token'] ?? '';

    if (
        $token === '' ||
        !preg_match('/^[a-f0-9]{64}$/', $token)
    ) {
        $token = bin2hex(random_bytes(32));

        setcookie('csrf_token', $token, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    return $token;
}

function checkCsrfToken(): bool
{
    $cookieToken = $_COOKIE['csrf_token'] ?? '';
    $postToken   = $_POST['csrf_token'] ?? '';

    return $cookieToken !== ''
        && $postToken !== ''
        && hash_equals($cookieToken, $postToken);
}

// --------------------------------------------------
// WgTerm一覧表示
// --------------------------------------------------
function showWgTerm(string $strMsg=""): void
{

    //ステータス更新
    updateLastConnectedAt();

    $data = [];
    while(true) {
        $fp = fopen(SAVE_FILE, 'r');

        if ($fp === false) {
            $strMsg = "端末設定ファイルの読み込みに失敗しました";
            break;          
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

        if ($json === false) {
            $strMsg = "端末設定ファイルの読み込みに失敗しました";
            break;
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            $data = [];
            $strMsg = "端末設定JSONのデコードに失敗しました";
            break;
        }
        
        break;
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
    if ($strMsg !== '') {
        echo '<p style="border: 1px solid #999;">' . h($strMsg) . '</p>';
    }
    echo '<details style="border: 1px solid #999;">'
    .'<summary>子側に中央サーバーを登録する場合の記述</summary>'
    .'[Interface]<br>'
    .'PrivateKey=xxxx<br>'
    .'ListenPort=51820<br>'
    .'#自身のIPを/32でセット<br>'
    .'Address=192.168.255.xxx/32<br>'
    .'[Peer]<br>'
    .'# サーバーの公開鍵<br>'
    .'PublicKey = ' . ROOT_PUB_KEY . '<br>'
    .'# サーバーのIPは' . ROOT_IP .'<br>'
    . 'AllowedIPs = 192.168.255.0/24<br>'
    . 'Endpoint = エンドポイントドメイン:'. ROOT_PORT .'<br></details>'; 


    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    foreach (PARAM_KEYS as $key) {
        echo '<th>' . htmlspecialchars(
            (string)$key,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) . '</th>';
    }
    echo '<th><a href="' . h($_SERVER['SCRIPT_NAME']) . '?type=edit&pubkey">新規登録</a></th>';
    echo '</tr>';
    echo '</thead>';

    echo '<tbody>';

    foreach ($data as $pubkey => $item) {
        echo '<tr>';
        foreach (PARAM_KEYS as $key) {
            $value = $item[$key] ?? '';
            echo '<td>' . htmlspecialchars(
                (string)$value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . '</td>';
        }
        echo '<td><a href="' . h($_SERVER['SCRIPT_NAME']) . '?type=edit&pubkey=' . urlencode($pubkey) . '">編集</a></td>';
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

// POSTをGETより優先して取得する
$type = $_POST['type'] ?? $_GET['type'] ?? '';

// 処理振り分け
switch ($type) {
case 'register':
    // CSRFトークンの検証
    if (!checkCsrfToken()) {
        http_response_code(403);
        exit;
    }
    $result = registerTerm();
    if ($result[0] === true) {
        //リロード時に再登録されないようにリダイレクトする
        header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?type=show');
        exit();
    } else {
        // エラー時は編集フォームに戻す
        editWgTerm($result[1], $result[2]);
    }
    break;
case 'edit':
    editWgTerm();
    break;
case 'csv':
    csvWgTerm();
    break;
case 'show':
default:
    showWgTerm();
}

exit;

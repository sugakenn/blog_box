<?php
/**
 * WireGuardコンフィグファイル生成
 * root以外に実行、表示させないようにする
 * (private keyを含むため)
 * 
 * 主にバックアップ用
 * beaconでIPを収集して、非常時はポートフォワードで接続する
 * 
 */
declare(strict_types=1);

define('WG_PRIVATE_KEY', 'YOUR_WG_PRIVATE_KEY');
define('LISTEN_PORT', '51820');

define('JSON_FILE', '/var/www/html/data/wg-beacon.json');
define('WG_CONFIG_PATH', '/etc/wireguard/wg0.conf');

function makeWgConfig(): bool
{
    if (file_exists(WG_CONFIG_PATH)) {
        //既存のコンフィグをバックアップ
        $timestamp = date('Ymd_His');
        $backupPath = WG_CONFIG_PATH . '_' . $timestamp;
        if (!rename(WG_CONFIG_PATH, $backupPath)) {
            echo "Failed to backup existing WireGuard config file.\n";
            return false;
        }
    }

    //JSONファイル読み込み
    $fp = fopen(JSON_FILE, 'r');
    if ($fp === false) {
        return false;
    }
    $json = stream_get_contents($fp);
    fclose($fp);
    $data = json_decode($json ?: '{}', true);
    if (!is_array($data)) {
        return false;
    }

    $config = "[Interface]\n";
    $config .= "PrivateKey = " . WG_PRIVATE_KEY . "\n";
    $config .= "ListenPort = " . LISTEN_PORT . "\n";
    $config .= "Address = 172.30.255.1/24\n";

    // Peer設定
    foreach ($data as $user => $item) {
        
        if (!isset($item['pubkey']) || !isset($item['tunnel_ip'])) {
            continue;
        }

        //activeなユーザーのみを対象にする
        if (!isset($item['active']) || $item['active'] !== 'active') {
            continue;
        }    

        $config .= "\n[Peer]\n";
        $config .="# User: " . $user . ":" . $item['name'] . "\n";
        $config .= "PublicKey = " . $item['pubkey'] . "\n";
        $config .= "AllowedIPs = " . $item['tunnel_ip'] . "\n";

        if (isset($item['endpoint']) && isset($item['port'])) {
            $config .= "Endpoint = " . $item['endpoint'] . ":" . $item['port'] . "\n";
        }
    }

    if (file_put_contents(WG_CONFIG_PATH, $config, LOCK_EX) === false) {
        return false;
    }

    return true;
}
function wireGuardSync(): void
{
    exec('ip link show wg0 > /dev/null 2>&1', $output, $returnCode);

    if ($returnCode === 0) {
        // wg0起動済み → 新しい設定を同期
        // stripはwg-quickの設定ファイルをwgコマンド用の設定に変換するために使用されます
        // syncconfは既存のWireGuardインターフェースの設定を新しい設定に置き換えるために使用されます
        // これにより、WireGuardインターフェースを再起動せずに設定を更新できます。
        // ただし、設定ファイル中に存在しないPeerは削除されるため、注意が必要です。
        exec(
            'wg-quick strip wg0 | wg syncconf wg0 /dev/stdin 2>&1',
            $output,
            $returnCode
        );
    } else {
        // wg0未起動 → 起動
        exec(
            'wg-quick up wg0 2>&1',
            $output,
            $returnCode
        );
    }

    if ($returnCode !== 0) {
        echo implode(PHP_EOL, $output);
        exit(1);
    }
}

//ENTRY POINT
if (!makeWgConfig()) {
    echo "Failed to create WireGuard config file.\n";
    exit(1);
}
echo "WireGuard config file created successfully at: " . WG_CONFIG_PATH . "\n";

//wg実行
wireGuardSync();

exit(0);

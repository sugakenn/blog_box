<?php
/**
 * WireGuardコンフィグファイル生成
 * root以外に実行、表示させないようにする
 * (private keyを含むため)
 * 
 */
declare(strict_types=1);

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// ロケール
setlocale(LC_ALL, 'ja_JP.UTF-8');

define('WG_PRIVATE_KEY', 'YOUR_WG_PRIVATE_KEY');
define('LISTEN_PORT','51820');
define('JSON_FILE', '/var/www/html/data/wg-beacon.json');

function makeWgConfig(?string $strSavePath =null): bool
{
    $strSavePath ??= '/etc/wireguard/wg0_'.date('Ymd_His').'.conf';

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

        $config .= "\n[Peer]\n";
        $config .="# User: " . $user . ":" . $item['name'] . "\n";
        $config .= "PublicKey = " . $item['pubkey'] . "\n";
        $config .= "AllowedIPs = " . $item['tunnel_ip'] . "\n";

        $endpoint = $item['endpoint'] ?? '';
        $port = $item['port'] ?? null;
        
        $validIp = filter_var(
            $endpoint,
            FILTER_VALIDATE_IP
        ) !== false;
        
        $validPort = filter_var(
            $port,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 65535
                ]
            ]
        ) !== false;
        
        if ($validIp && $validPort) {
        
            // IPv6の場合は [] で囲む
            if (filter_var(
                $endpoint,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6
            ) !== false) {
                $endpoint = '[' . $endpoint . ']';
            }
        
            $config .= "Endpoint = "
                . $endpoint
                . ":"
                . $port
                . "\n";
        }
    }

    if (file_put_contents($strSavePath, $config, LOCK_EX) === false) {
        return false;
    }

    return true;
}

//ENTRY POINT
$strSavePath = $argv[1] ?? null;
if (!makeWgConfig($strSavePath)) {
    echo "Failed to create WireGuard config file.\n";
    exit(1);
}
echo "WireGuard config file created successfully at: " . ($strSavePath ?? 'default path') . "\n";
exit(0);

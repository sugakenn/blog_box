# WireGuard用の定時更新用 .ps1ファイル
# 次のようにしてWindowsタスクへ登録
# [全般]
# タスク実行時に使うユーザー 「SYSTEM」
# [トリガー]
# タスクの開始「任意のユーザーのログイン時」
# 遅延時間 「1分」
# 繰り返し時間 「1時間/無期限」
# [操作]
# プログラムの開始
# プログラム powershell.exe / 引き数 このファイルへのフルパス / 開始 このファイルがあるフォルダ 
# [条件]
#「AC電源でなくても実行」「任意の接続が使用可能な時に実行」
# 任意の接続の代わりにwg0を指定しても動きました
# このファイルはユーザー権限では書き込めないようにしておく必要がある
# ProgramDataの任意のフォルダを作成してそこへ配置、
# 実行プログラムpowershell.exe 引き数にファイルフルパス、実行フォルダをこのファイルの親フォルダにする

# 設定 -------------------------------------------------
$wg = "C:\Program Files\WireGuard\wg.exe"
$pubkey = "中央サーバーのPUBLIC_KEY"
$net = "wg0(トンネル名)"
$endpoint = "endpoint.jp:51820(中央サーバー)"
$logFile = "C:\ProgramData\WGBeacon\wg-beacon.log(ログファイル)"
$pingIp = "192.168.255.1(中央サーバーのWGアドレス)"
# -------------------------------------------------------

$msg = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') INFO WireGuard endpoint update start"
Add-Content -Path $logFile -Value $msg

& $wg set $net peer $pubkey endpoint $endpoint


if ($LASTEXITCODE -ne 0) {
    $msg = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') ERROR WireGuard endpoint update fail"
    Add-Content -Path $logFile -Value $msg
    exit 1
}

$pingResult = ping $pingIp 2>&1
Add-Content -Path $logFile -Value $pingResult
exit 0

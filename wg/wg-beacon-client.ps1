# New Beacon For Windows (グローバルにPOST)

$term_id = "user"
$postUrl = "https://example.com/api/ip"
$type = "set"
$apikey  = "YOUR_SECRET_API_KEY"

# POST -k:証明書を検証しない -s:silent進捗表示をしない -f:HTTPエラーを成功にしない 
curl.exe -k -s -f  --data-urlencode "user=$term_id" --data-urlencode "key=$apikey"  --data-urlencode "type=$type" $postUrl


# POST失敗
if ($LASTEXITCODE -ne 0) {
    exit 1
}

exit 0
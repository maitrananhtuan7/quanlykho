<?php
// --- CẤU HÌNH R2 ---
$r2_config = [
    'account_id' => 'b1bcbd96faa1d27832d357e32ac9dff9',
    'access_key' => 'd236954195506743978019bd23572ddf',
    'secret_key' => '7923dbe6eab14f0a38a386c4cafdfc3465610a2c64a0f771704a2d69a941ccdd',
    'bucket'     => 'apro-warehouse',
    'public_path'=> 'https://pub-653f2eecbee141b2a9ed804cbf5196d9.r2.dev'
];

class R2Manager {
    private $key, $secret, $accountId, $bucket, $region = 'auto';

    public function __construct($key, $secret, $accountId, $bucket) {
        $this->key = $key;
        $this->secret = $secret;
        $this->accountId = trim($accountId);
        $this->bucket = trim($bucket);
    }

    // Tạo URL để Client tự PUT file lên
    public function generatePresignedUrl($objectKey, $contentType, $expires = 3600) {
        $host = "{$this->bucket}.{$this->accountId}.r2.cloudflarestorage.com";
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $canonicalUri = '/' . str_replace('%2F', '/', rawurlencode($objectKey));
        
        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->key}/{$date}/{$this->region}/s3/aws4_request",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => 'host;content-type',
        ];
        ksort($params);
        $queryString = http_build_query($params);
        $canonicalHeaders = "content-type:{$contentType}\nhost:{$host}\n";
        $signedHeaders = "content-type;host";
        $canonicalRequest = "PUT\n{$canonicalUri}\n{$queryString}\n{$canonicalHeaders}\n{$signedHeaders}\nUNSIGNED-PAYLOAD";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$date}/{$this->region}/s3/aws4_request\n" . hash('sha256', $canonicalRequest);
        $signature = $this->calculateSignature($stringToSign, $date);
        
        return "https://{$host}{$canonicalUri}?{$queryString}&X-Amz-Signature={$signature}";
    }

    // Hàm LIST dùng cURL
    public function listFiles($prefix = '') {
        $query = ['list-type' => '2', 'prefix' => $prefix];
        return $this->executeCurl('GET', '/', $query);
    }

    // Hàm DELETE dùng cURL
    public function deleteFile($objectKey) {
        $path = '/' . ltrim($objectKey, '/');
        $result = $this->executeCurl('DELETE', $path);
        return ($result === "" || empty($result)) ? true : $result;
    }
    
    // tạm chưa test
    // Hàm upload file
    public function upload($folder, $fileData, $fileNameOriginal) {
        $fileName = $folder . '/' . uniqid() . '_' . $fileNameOriginal;
        $this->executeCurl('PUT', $fileName, $fileData);
        return [
            'url' => $this->config['public_path'] . '/' . $fileName,
            'path' => $fileName
        ];
    }
    
    // tạm chưa test
    // Hàm Move file (Tối ưu: gọi trực tiếp lệnh S3 Copy không qua HTTP)
    public function moveTempToMain($tempPath) {
        $mainPath = str_replace('temp/', 'main/', $tempPath);
        // Copy từ nguồn sang đích
        $this->executeCurl('PUT', $mainPath, null, [
            'x-amz-copy-source' => '/' . $this->config['bucket'] . '/' . $tempPath
        ]);
        // Xóa file tạm
        $this->executeCurl('DELETE', $tempPath);
        return $this->config['public_path'] . '/' . $mainPath;
    }

    // Hàm thực thi cURL chung cho các tác vụ chuẩn S3
    private function executeCurl($method, $path, $query = [], $payload = '') {
        $host = "{$this->bucket}.{$this->accountId}.r2.cloudflarestorage.com";
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        ksort($query);
        $queryString = http_build_query($query);
        $payloadHash = hash('sha256', $payload);
        
        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = "host;x-amz-content-sha256;x-amz-date";
        
        $canonicalRequest = "{$method}\n{$path}\n{$queryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signature = $this->calculateSignature($stringToSign, $date);
        
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        $url = "https://{$host}{$path}" . ($queryString ? "?{$queryString}" : "");

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: {$authorization}",
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: {$payloadHash}"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ($method === 'GET' && str_contains($path, '/')) ? $this->parseXml($response) : $response;
        }
        return ['error' => "R2 Error ($httpCode)", 'raw' => $response];
    }

    private function calculateSignature($stringToSign, $date) {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    private function parseXml($xmlString) {
        $xml = @simplexml_load_string($xmlString);
        if (!$xml) return [];
        $files = [];
        foreach ($xml->Contents as $content) {
            $files[] = ['key' => (string)$content->Key, 'size' => (int)$content->Size];
        }
        return $files;
    }
}

// Xử lý Request từ AJAX (Frontend gọi)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? '';
    
    $r2 = new R2Manager(
        $r2_config['access_key'],
        $r2_config['secret_key'],
        $r2_config['account_id'],
        $r2_config['bucket']
    );
    
    // 1. LẤY PRESIGNED URL
    if ($action === 'get-presigned-url') {
        $fileName = $_GET['file'] ?? 'upload.jpg';
        $fileType = $_GET['type'] ?? 'image/jpeg';
        $url = $r2->generatePresignedUrl($fileName, $fileType);
        echo json_encode(['upload_url'=>$url]);
        exit;
    }
    
    // 2. LIST FILES
    if ($action === 'list-files') {
        $prefix = $_GET['prefix'] ?? '';
        $result = $r2->listFiles($prefix);
        echo json_encode(['result'=>$result]);
        exit;
    }
    
    // 3. DELETE FILE
    if ($action === 'delete-file') {
        $key = $_GET['key'] ?? '';
        if (!$key) {
            echo json_encode(['message' => 'Missing key']);
            exit;
        }
        $result = $r2->deleteFile($key);
        echo json_encode(['result'=>$result]);
        exit;
    }
    
    // tạm chưa test
    if ($_GET['action'] == 'upload' && isset($_FILES['file'])) {
        $result = $r2->upload('temp', file_get_contents($_FILES['file']['tmp_name']), $_FILES['file']['name']);
        echo json_encode(['result'=>$result]);
        exit;
    }
}
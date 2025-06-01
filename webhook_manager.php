<?php
/**
 * Ana Sunucu - Webhook Manager v2.1
 * Hibrit Mimari için webhook yönetimi ve dağıtım sistemi
 * 
 * Fonksiyonlar:
 * - SYNC modundaki mağazalara webhook gönderimi
 * - Webhook endpoint yönetimi
 * - Delivery tracking ve retry logic
 * - Webhook güvenlik doğrulaması
 */

require_once '../../session_manager.php';
require_once '../../db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Webhook-Signature');

error_reporting(0);
ini_set('display_errors', 0);

$response = [
    'success' => false,
    'message' => '',
    'data' => []
];

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? 'status';
    
    switch ($action) {
        case 'send':
            $response = sendWebhook();
            break;
            
        case 'batch_send':
            $response = batchSendWebhooks();
            break;
            
        case 'register_endpoint':
            $response = registerWebhookEndpoint();
            break;
            
        case 'update_endpoint':
            $response = updateWebhookEndpoint();
            break;
            
        case 'test_endpoint':
            $response = testWebhookEndpoint();
            break;
            
        case 'delivery_status':
            $response = getDeliveryStatus();
            break;
            
        case 'retry_failed':
            $response = retryFailedWebhooks();
            break;
            
        case 'endpoints_list':
            $response = getWebhookEndpoints();
            break;
            
        case 'delivery_logs':
            $response = getDeliveryLogs();
            break;
            
        case 'configure_security':
            $response = configureWebhookSecurity();
            break;
            
        case 'status':
        default:
            $response = getWebhookStatus();
            break;
    }

} catch (Exception $e) {
    error_log('Webhook Manager hatası: ' . $e->getMessage());
    $response['message'] = $e->getMessage();
} catch (Throwable $t) {
    error_log('Webhook Manager kritik hata: ' . $t->getMessage());
    $response['message'] = 'Sistem hatası: ' . $t->getMessage();
}

echo json_encode($response);

// ==================== WEBHOOK GÖNDERİM FONKSİYONLARI ====================

/**
 * Tek webhook gönder
 */
function sendWebhook() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $required_fields = ['store_id', 'event_type', 'data'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Eksik parametre: {$field}");
        }
    }
    
    $store_id = $input['store_id'];
    $event_type = $input['event_type'];
    $data = $input['data'];
    $priority = $input['priority'] ?? 'normal';
    
    // Mağazanın SYNC modunda olduğunu kontrol et
    if (!isStoreInSyncMode($store_id)) {
        throw new Exception("Mağaza SYNC modunda değil: {$store_id}");
    }
    
    // Webhook endpoint'ini al
    $endpoint = getStoreWebhookEndpoint($store_id);
    if (!$endpoint) {
        throw new Exception("Mağaza için webhook endpoint bulunamadı: {$store_id}");
    }
    
    // Webhook'u gönder
    $delivery_result = deliverWebhook($store_id, $endpoint, $event_type, $data, $priority);
    
    return [
        'success' => $delivery_result['success'],
        'message' => $delivery_result['success'] ? 'Webhook başarıyla gönderildi' : 'Webhook gönderimi başarısız',
        'delivery_id' => $delivery_result['delivery_id'],
        'endpoint' => $endpoint,
        'response_time' => $delivery_result['response_time'] ?? null,
        'status_code' => $delivery_result['status_code'] ?? null
    ];
}

/**
 * Toplu webhook gönder
 */
function batchSendWebhooks() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['webhooks']) || !is_array($input['webhooks'])) {
        throw new Exception('Webhooks array gerekli');
    }
    
    $results = [];
    $success_count = 0;
    $failed_count = 0;
    
    foreach ($input['webhooks'] as $webhook_data) {
        try {
            // Her webhook için gerekli alanları kontrol et
            $required_fields = ['store_id', 'event_type', 'data'];
            foreach ($required_fields as $field) {
                if (!isset($webhook_data[$field])) {
                    throw new Exception("Webhook eksik parametre: {$field}");
                }
            }
            
            $store_id = $webhook_data['store_id'];
            $event_type = $webhook_data['event_type'];
            $data = $webhook_data['data'];
            $priority = $webhook_data['priority'] ?? 'normal';
            
            // SYNC mode kontrolü
            if (!isStoreInSyncMode($store_id)) {
                throw new Exception("Mağaza SYNC modunda değil: {$store_id}");
            }
            
            // Endpoint al
            $endpoint = getStoreWebhookEndpoint($store_id);
            if (!$endpoint) {
                throw new Exception("Webhook endpoint bulunamadı: {$store_id}");
            }
            
            // Webhook gönder
            $delivery_result = deliverWebhook($store_id, $endpoint, $event_type, $data, $priority);
            
            $results[] = [
                'store_id' => $store_id,
                'event_type' => $event_type,
                'success' => $delivery_result['success'],
                'delivery_id' => $delivery_result['delivery_id'],
                'message' => $delivery_result['success'] ? 'Başarılı' : ($delivery_result['error'] ?? 'Bilinmeyen hata')
            ];
            
            if ($delivery_result['success']) {
                $success_count++;
            } else {
                $failed_count++;
            }
            
        } catch (Exception $e) {
            $results[] = [
                'store_id' => $webhook_data['store_id'] ?? 'unknown',
                'event_type' => $webhook_data['event_type'] ?? 'unknown',
                'success' => false,
                'delivery_id' => null,
                'message' => $e->getMessage()
            ];
            $failed_count++;
        }
    }
    
    return [
        'success' => true,
        'message' => "Toplu webhook gönderimi tamamlandı: {$success_count} başarılı, {$failed_count} başarısız",
        'summary' => [
            'total' => count($input['webhooks']),
            'successful' => $success_count,
            'failed' => $failed_count
        ],
        'results' => $results
    ];
}

// ==================== ENDPOINT YÖNETİM FONKSİYONLARI ====================

/**
 * Webhook endpoint kaydet/güncelle
 */
function registerWebhookEndpoint() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $required_fields = ['store_id', 'endpoint_url'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Eksik parametre: {$field}");
        }
    }
    
    $store_id = $input['store_id'];
    $endpoint_url = $input['endpoint_url'];
    $secret_key = $input['secret_key'] ?? generateWebhookSecret();
    $is_active = $input['is_active'] ?? true;
    
    // URL format kontrolü
    if (!filter_var($endpoint_url, FILTER_VALIDATE_URL)) {
        throw new Exception('Geçersiz endpoint URL');
    }
    
    // Mağaza SYNC modunda mı kontrol et
    if (!isStoreInSyncMode($store_id)) {
        throw new Exception("Mağaza SYNC modunda değil: {$store_id}");
    }
    
    try {
        $conn->beginTransaction();
        
        // Webhook endpoint'i kaydet
        $stmt = $conn->prepare("
            INSERT INTO webhook_endpoints (store_id, endpoint_url, secret_key, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            endpoint_url = VALUES(endpoint_url),
            secret_key = VALUES(secret_key),
            is_active = VALUES(is_active),
            updated_at = NOW()
        ");
        $stmt->execute([$store_id, $endpoint_url, $secret_key, $is_active ? 1 : 0]);
        
        // Test webhook gönder
        $test_result = testWebhookDelivery($store_id, $endpoint_url, $secret_key);
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => 'Webhook endpoint başarıyla kaydedildi',
            'store_id' => $store_id,
            'endpoint_url' => $endpoint_url,
            'secret_key' => $secret_key,
            'test_result' => $test_result
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw new Exception('Endpoint kayıt hatası: ' . $e->getMessage());
    }
}

/**
 * Webhook endpoint test et
 */
function testWebhookEndpoint() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $store_id = $input['store_id'] ?? null;
    $endpoint_url = $input['endpoint_url'] ?? null;
    
    if (!$store_id) {
        throw new Exception('Store ID gerekli');
    }
    
    // Endpoint URL belirtilmemişse kayıtlı olanı kullan
    if (!$endpoint_url) {
        $endpoint_url = getStoreWebhookEndpoint($store_id);
        if (!$endpoint_url) {
            throw new Exception('Mağaza için webhook endpoint bulunamadı');
        }
    }
    
    // Test verisi hazırla
    $test_data = [
        'event_type' => 'test',
        'timestamp' => time(),
        'test_id' => uniqid('test_'),
        'message' => 'Webhook endpoint test mesajı'
    ];
    
    // Test webhook'u gönder
    $result = deliverWebhook($store_id, $endpoint_url, 'test', $test_data, 'high');
    
    return [
        'success' => $result['success'],
        'message' => $result['success'] ? 'Webhook testi başarılı' : 'Webhook testi başarısız',
        'endpoint_url' => $endpoint_url,
        'response_time' => $result['response_time'] ?? null,
        'status_code' => $result['status_code'] ?? null,
        'error' => $result['error'] ?? null
    ];
}

/**
 * Webhook endpoint listesi
 */
function getWebhookEndpoints() {
    global $conn;
    
    $store_id = $_GET['store_id'] ?? null;
    
    $where_clause = $store_id ? 'WHERE we.store_id = ?' : '';
    $params = $store_id ? [$store_id] : [];
    
    $stmt = $conn->prepare("
        SELECT we.store_id, m.ad as store_name,
               we.endpoint_url, we.is_active, we.created_at, we.updated_at,
               (SELECT COUNT(*) FROM webhook_deliveries wd 
                WHERE wd.store_id = we.store_id AND wd.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as deliveries_24h,
               (SELECT COUNT(*) FROM webhook_deliveries wd 
                WHERE wd.store_id = we.store_id AND wd.status = 'failed' 
                AND wd.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as failed_24h
        FROM webhook_endpoints we
        JOIN magazalar m ON we.store_id = m.id
        {$where_clause}
        ORDER BY we.store_id
    ");
    $stmt->execute($params);
    $endpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Her endpoint için son delivery durumunu ekle
    foreach ($endpoints as &$endpoint) {
        $stmt = $conn->prepare("
            SELECT status, created_at, response_time, status_code
            FROM webhook_deliveries
            WHERE store_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$endpoint['store_id']]);
        $last_delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $endpoint['last_delivery'] = $last_delivery;
        $endpoint['health_status'] = calculateEndpointHealth($endpoint['failed_24h'], $endpoint['deliveries_24h']);
    }
    
    return [
        'success' => true,
        'message' => 'Webhook endpoint listesi alındı',
        'endpoints' => $endpoints,
        'total_count' => count($endpoints)
    ];
}

// ==================== DELİVERY TAKİP FONKSİYONLARI ====================

/**
 * Delivery durumu sorgula
 */
function getDeliveryStatus() {
    global $conn;
    
    $delivery_id = $_GET['delivery_id'] ?? null;
    $store_id = $_GET['store_id'] ?? null;
    $limit = min(100, (int)($_GET['limit'] ?? 50));
    
    if ($delivery_id) {
        // Belirli delivery
        $stmt = $conn->prepare("
            SELECT wd.*, m.ad as store_name
            FROM webhook_deliveries wd
            JOIN magazalar m ON wd.store_id = m.id
            WHERE wd.delivery_id = ?
        ");
        $stmt->execute([$delivery_id]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$delivery) {
            throw new Exception('Delivery bulunamadı');
        }
        
        return [
            'success' => true,
            'message' => 'Delivery durumu alındı',
            'delivery' => $delivery
        ];
        
    } else {
        // Delivery listesi
        $where_clause = $store_id ? 'WHERE wd.store_id = ?' : '';
        $params = $store_id ? [$store_id, $limit] : [$limit];
        
        $stmt = $conn->prepare("
            SELECT wd.delivery_id, wd.store_id, m.ad as store_name,
                   wd.event_type, wd.status, wd.created_at, wd.delivered_at,
                   wd.response_time, wd.status_code, wd.retry_count
            FROM webhook_deliveries wd
            JOIN magazalar m ON wd.store_id = m.id
            {$where_clause}
            ORDER BY wd.created_at DESC
            LIMIT ?
        ");
        $stmt->execute($params);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Özet istatistikler
        $stmt = $conn->prepare("
            SELECT status, COUNT(*) as count
            FROM webhook_deliveries wd
            {$where_clause}
            AND wd.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY status
        ");
        $stmt->execute($store_id ? [$store_id] : []);
        $status_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'message' => 'Delivery listesi alındı',
            'deliveries' => $deliveries,
            'status_summary' => $status_summary,
            'total_count' => count($deliveries)
        ];
    }
}

/**
 * Başarısız webhook'ları yeniden dene
 */
function retryFailedWebhooks() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $store_id = $input['store_id'] ?? null;
    $max_retries = $input['max_retries'] ?? 3;
    $age_limit_hours = $input['age_limit_hours'] ?? 24;
    
    // Yeniden denenecek webhook'ları bul
    $where_clause = $store_id ? 'AND wd.store_id = ?' : '';
    $params = [$max_retries, $age_limit_hours];
    if ($store_id) {
        $params[] = $store_id;
    }
    
    $stmt = $conn->prepare("
        SELECT wd.delivery_id, wd.store_id, wd.event_type, wd.payload,
               wd.retry_count, we.endpoint_url, we.secret_key
        FROM webhook_deliveries wd
        JOIN webhook_endpoints we ON wd.store_id = we.store_id
        WHERE wd.status = 'failed' 
        AND wd.retry_count < ?
        AND wd.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        AND we.is_active = 1
        {$where_clause}
        ORDER BY wd.created_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $failed_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $retry_results = [];
    $success_count = 0;
    $failed_count = 0;
    
    foreach ($failed_deliveries as $delivery) {
        try {
            // Payload'ı decode et
            $payload_data = json_decode($delivery['payload'], true);
            
            // Yeniden dene
            $retry_result = deliverWebhook(
                $delivery['store_id'],
                $delivery['endpoint_url'],
                $delivery['event_type'],
                $payload_data,
                'retry',
                $delivery['delivery_id']
            );
            
            $retry_results[] = [
                'delivery_id' => $delivery['delivery_id'],
                'store_id' => $delivery['store_id'],
                'success' => $retry_result['success'],
                'message' => $retry_result['success'] ? 'Başarılı' : ($retry_result['error'] ?? 'Bilinmeyen hata')
            ];
            
            if ($retry_result['success']) {
                $success_count++;
            } else {
                $failed_count++;
            }
            
        } catch (Exception $e) {
            $retry_results[] = [
                'delivery_id' => $delivery['delivery_id'],
                'store_id' => $delivery['store_id'],
                'success' => false,
                'message' => $e->getMessage()
            ];
            $failed_count++;
        }
    }
    
    return [
        'success' => true,
        'message' => "Retry işlemi tamamlandı: {$success_count} başarılı, {$failed_count} başarısız",
        'summary' => [
            'total_retries' => count($failed_deliveries),
            'successful' => $success_count,
            'failed' => $failed_count
        ],
        'results' => $retry_results
    ];
}

// ==================== CORE WEBHOOK FONKSİYONLARI ====================

/**
 * Webhook deliver et
 */
function deliverWebhook($store_id, $endpoint_url, $event_type, $data, $priority = 'normal', $retry_delivery_id = null) {
    global $conn;
    
    $delivery_id = $retry_delivery_id ?: uniqid('webhook_');
    $start_time = microtime(true);
    
    try {
        // Webhook payload hazırla
        $payload = [
            'event_type' => $event_type,
            'store_id' => $store_id,
            'timestamp' => time(),
            'delivery_id' => $delivery_id,
            'data' => $data
        ];
        
        // Güvenlik imzası oluştur
        $secret_key = getStoreWebhookSecret($store_id);
        $signature = generateWebhookSignature($payload, $secret_key);
        
        // HTTP headers
        $headers = [
            'Content-Type: application/json',
            'User-Agent: POS-Webhook-v2.1',
            'X-Webhook-Delivery: ' . $delivery_id,
            'X-Webhook-Event: ' . $event_type,
            'X-Webhook-Signature: ' . $signature,
            'X-Webhook-Timestamp: ' . time()
        ];
        
        // cURL ile gönder
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $response = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $response_time = (microtime(true) - $start_time) * 1000; // ms
        $error = curl_error($ch);
        curl_close($ch);
        
        // Sonucu değerlendir
        $success = ($status_code >= 200 && $status_code < 300);
        $status = $success ? 'delivered' : 'failed';
        
        // Delivery kaydını güncelle/oluştur
        if ($retry_delivery_id) {
            // Retry case: mevcut kaydı güncelle
            $stmt = $conn->prepare("
                UPDATE webhook_deliveries 
                SET status = ?, delivered_at = NOW(), response_time = ?, 
                    status_code = ?, error_message = ?, retry_count = retry_count + 1
                WHERE delivery_id = ?
            ");
            $stmt->execute([$status, $response_time, $status_code, $error, $delivery_id]);
        } else {
            // Yeni delivery kaydı oluştur
            $stmt = $conn->prepare("
                INSERT INTO webhook_deliveries (
                    delivery_id, store_id, event_type, endpoint_url, payload,
                    status, created_at, delivered_at, response_time, status_code, 
                    error_message, priority, retry_count
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $delivery_id, $store_id, $event_type, $endpoint_url, json_encode($payload),
                $status, $success ? 'NOW()' : null, $response_time, $status_code, 
                $error, $priority
            ]);
        }
        
        return [
            'success' => $success,
            'delivery_id' => $delivery_id,
            'status_code' => $status_code,
            'response_time' => $response_time,
            'error' => $error
        ];
        
    } catch (Exception $e) {
        // Hata durumunda kayıt oluştur
        if (!$retry_delivery_id) {
            $stmt = $conn->prepare("
                INSERT INTO webhook_deliveries (
                    delivery_id, store_id, event_type, endpoint_url, payload,
                    status, created_at, error_message, priority, retry_count
                ) VALUES (?, ?, ?, ?, ?, 'failed', NOW(), ?, ?, 0)
            ");
            $stmt->execute([
                $delivery_id, $store_id, $event_type, $endpoint_url, 
                json_encode(['error' => 'Payload oluşturulamadı']),
                $e->getMessage(), $priority
            ]);
        }
        
        return [
            'success' => false,
            'delivery_id' => $delivery_id,
            'error' => $e->getMessage()
        ];
    }
}

// ==================== YARDIMCI FONKSİYONLAR ====================

/**
 * Mağaza SYNC modunda mı kontrol et
 */
function isStoreInSyncMode($store_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT config_value 
        FROM store_config 
        WHERE magaza_id = ? AND config_key = 'operation_mode'
    ");
    $stmt->execute([$store_id]);
    $mode = $stmt->fetchColumn();
    
    return ($mode === 'SYNC');
}

/**
 * Mağaza webhook endpoint'i al
 */
function getStoreWebhookEndpoint($store_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT endpoint_url 
        FROM webhook_endpoints 
        WHERE store_id = ? AND is_active = 1
    ");
    $stmt->execute([$store_id]);
    
    return $stmt->fetchColumn();
}

/**
 * Mağaza webhook secret key al
 */
function getStoreWebhookSecret($store_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT secret_key 
        FROM webhook_endpoints 
        WHERE store_id = ? AND is_active = 1
    ");
    $stmt->execute([$store_id]);
    
    return $stmt->fetchColumn() ?: 'default_secret_key';
}

/**
 * Webhook imzası oluştur
 */
function generateWebhookSignature($payload, $secret_key) {
    $payload_string = is_array($payload) ? json_encode($payload) : $payload;
    return 'sha256=' . hash_hmac('sha256', $payload_string, $secret_key);
}

/**
 * Webhook secret key oluştur
 */
function generateWebhookSecret() {
    return bin2hex(random_bytes(32));
}

/**
 * Endpoint sağlık durumu hesapla
 */
function calculateEndpointHealth($failed_count, $total_count) {
    if ($total_count === 0) return 'unknown';
    
    $success_rate = (($total_count - $failed_count) / $total_count) * 100;
    
    if ($success_rate >= 95) return 'healthy';
    if ($success_rate >= 80) return 'warning';
    return 'unhealthy';
}

/**
 * Test webhook delivery
 */
function testWebhookDelivery($store_id, $endpoint_url, $secret_key) {
    $test_payload = [
        'event_type' => 'endpoint_test',
        'store_id' => $store_id,
        'timestamp' => time(),
        'test_message' => 'Webhook endpoint test - hibrit mimari v2.1'
    ];
    
    $signature = generateWebhookSignature($test_payload, $secret_key);
    
    $headers = [
        'Content-Type: application/json',
        'X-Webhook-Test: true',
        'X-Webhook-Signature: ' . $signature
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($test_payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => ($status_code >= 200 && $status_code < 300),
        'status_code' => $status_code,
        'error' => $error,
        'response' => $response
    ];
}

/**
 * Webhook genel durumu
 */
function getWebhookStatus() {
    global $conn;
    
    // Genel istatistikler
    $stmt = $conn->query("
        SELECT 
            COUNT(DISTINCT we.store_id) as total_endpoints,
            COUNT(DISTINCT CASE WHEN we.is_active = 1 THEN we.store_id END) as active_endpoints,
            (SELECT COUNT(*) FROM webhook_deliveries WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as deliveries_24h,
            (SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'delivered' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as successful_24h,
            (SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as failed_24h
        FROM webhook_endpoints we
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Son 7 günlük trend
    $stmt = $conn->query("
        SELECT DATE(created_at) as date,
               COUNT(*) as total_deliveries,
               COUNT(CASE WHEN status = 'delivered' THEN 1 END) as successful_deliveries,
               COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_deliveries
        FROM webhook_deliveries 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $daily_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Başarı oranı hesapla
    $success_rate = 0;
    if ($stats['deliveries_24h'] > 0) {
        $success_rate = ($stats['successful_24h'] / $stats['deliveries_24h']) * 100;
    }
    
    // SYNC modundaki mağaza sayısı
    $stmt = $conn->query("
        SELECT COUNT(*) as sync_stores
        FROM store_config 
        WHERE config_key = 'operation_mode' AND config_value = 'SYNC'
    ");
    $sync_stores = $stmt->fetchColumn();
    
    return [
        'success' => true,
        'message' => 'Webhook sistemi durumu alındı',
        'status' => [
            'total_endpoints' => $stats['total_endpoints'],
            'active_endpoints' => $stats['active_endpoints'],
            'sync_stores' => $sync_stores,
            'deliveries_24h' => $stats['deliveries_24h'],
            'successful_24h' => $stats['successful_24h'],
            'failed_24h' => $stats['failed_24h'],
            'success_rate' => round($success_rate, 2)
        ],
        'daily_trend' => $daily_trend,
        'architecture_version' => '2.1',
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

?>
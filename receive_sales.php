<?php
/**
 * Ana Sunucu - Satış Alma API'si v2.1
 * Mağazalardan gelen satışları alır, işler ve diğer mağazalara dağıtır
 * 
 * YENİ: Müşteri borçları, faturalar ve sistem ayarları merkezi
 */

require_once '../../session_manager.php';
require_once '../../db_connection.php';
require_once '../../stock_functions.php';

// CORS ve güvenlik ayarları
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Hata raporlama kapalı (production)
error_reporting(0);
ini_set('display_errors', 0);

$response = [
    'success' => false,
    'message' => '',
    'invoice_id' => null,
    'conflicts' => [],
    'sync_id' => null,
    'credit_id' => null, // ⭐ YENİ - Borç kaydı ID'si
    'webhook_results' => [] // ⭐ YENİ - Webhook sonuçları
];

try {
    // POST kontrolü
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Sadece POST metodu desteklenir');
    }

    // JSON verisini al
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Geçersiz JSON formatı');
    }

    // Gerekli alanları kontrol et
    $required_fields = ['sale', 'sync_metadata'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Eksik alan: $field");
        }
    }

    $sale_data = $input['sale'];
    $sync_metadata = $input['sync_metadata'];

    // Satış verisi kontrolü
    $required_sale_fields = ['fatura_no', 'magaza_id', 'items'];
    foreach ($required_sale_fields as $field) {
        if (!isset($sale_data[$field])) {
            throw new Exception("Eksik satış alanı: $field");
        }
    }

    // Mağaza kontrolü
    $stmt = $conn->prepare("SELECT id, ad FROM magazalar WHERE id = ?");
    $stmt->execute([$sale_data['magaza_id']]);
    $magaza = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$magaza) {
        throw new Exception('Geçersiz mağaza ID');
    }

    // Duplicate kontrol - aynı fiş numarası var mı?
    $stmt = $conn->prepare("SELECT id FROM satis_faturalari WHERE fatura_no = ?");
    $stmt->execute([$sale_data['fatura_no']]);
    if ($stmt->fetch()) {
        // Duplicate satış - başarılı olarak işaretle ama işlem yapma
        $response['success'] = true;
        $response['message'] = 'Satış zaten kaydedilmiş (duplicate)';
        $response['duplicate'] = true;
        echo json_encode($response);
        exit;
    }

    // Transaction başlat
    $conn->beginTransaction();

    // Sync ID oluştur
    $sync_id = generateSyncId($sale_data['magaza_id']);

    // Stok çakışma kontrolü
    $conflicts = checkStockConflicts($sale_data['items'], $sale_data['magaza_id'], $conn);
    
    if (!empty($conflicts)) {
        // Çakışma var - conflict_log'a kaydet
        foreach ($conflicts as $conflict) {
            logConflict('stock_conflict', $sale_data['magaza_id'], $conflict, $conn);
        }
        
        $response['conflicts'] = $conflicts;
        // Çakışma varsa bile satışı kaydet ama uyarı ver
        $response['has_conflicts'] = true;
    }

    // Ana satış faturası kaydet
    $invoice_id = saveSaleInvoice($sale_data, $conn);

    // Satış detaylarını kaydet
    foreach ($sale_data['items'] as $item) {
        saveSaleDetail($invoice_id, $item, $conn);
        
        // Stok hareketi ekle (ana sunucuda da stok tutuyoruz)
        addStockMovementForSale($item, $sale_data, $conn);
    }

    // Müşteri işlemleri (varsa) - ⭐ MERKEZİ
    if (isset($sale_data['customer_id']) && $sale_data['customer_id']) {
        processCustomerOperations($invoice_id, $sale_data, $conn);
    }

    // ⭐ YENİ - Borç kaydı (varsa) - MERKEZİ
    $credit_id = null;
    if (isset($sale_data['odeme_yontemi']) && $sale_data['odeme_yontemi'] === 'borc') {
        $credit_id = processDebtRecord($invoice_id, $sale_data, $conn);
        $response['credit_id'] = $credit_id;
    }

    // Sync metadata kaydet
    saveSyncMetadata($sale_data['magaza_id'], 'satis_faturalari', $sync_metadata, $conn);

    $conn->commit();

    // ⭐ YENİ - Transaction sonrası webhook dağıtımları (MERKEZİ veriler için)
    $webhook_results = [];
    
    // Fatura güncelleme webhook'u (merkezi)
    $webhook_results[] = triggerInvoiceWebhook($invoice_id, 'invoice_added', $sale_data['magaza_id']);
    
    // Müşteri güncellemesi webhook'u (merkezi)
    if (isset($sale_data['customer_id'])) {
        $webhook_results[] = triggerCustomerWebhook($sale_data['customer_id'], 'customer_updated', $sale_data['magaza_id']);
    }
    
    // ⭐ YENİ - Borç webhook'u (merkezi)
    if ($credit_id) {
        $webhook_results[] = triggerCreditWebhook($credit_id, 'customer_credit_added', $sale_data['magaza_id']);
    }

    $response['success'] = true;
    $response['message'] = 'Satış başarıyla kaydedildi';
    $response['invoice_id'] = $invoice_id;
    $response['sync_id'] = $sync_id;
    $response['webhook_results'] = array_filter($webhook_results); // Boş olanları filtrele

} catch (Exception $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log('Satış alma hatası: ' . $e->getMessage());
    $response['message'] = $e->getMessage();
    
} catch (Throwable $t) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log('Kritik hata: ' . $t->getMessage());
    $response['message'] = 'Sistem hatası: ' . $t->getMessage();
}

echo json_encode($response);

// ==================== YARDIMCI FONKSİYONLAR ====================

/**
 * Sync ID oluştur
 */
function generateSyncId($magaza_id) {
    return 'SYNC_' . $magaza_id . '_' . time() . '_' . mt_rand(1000, 9999);
}

/**
 * Stok çakışma kontrolü
 */
function checkStockConflicts($items, $magaza_id, $conn) {
    $conflicts = [];
    
    foreach ($items as $item) {
        // Mağaza stoğunu kontrol et
        $stmt = $conn->prepare("
            SELECT ms.stok_miktari as magaza_stok,
                   us.stok_miktari as toplam_stok,
                   us.ad as urun_adi
            FROM magaza_stok ms
            JOIN urun_stok us ON ms.barkod = us.barkod
            WHERE ms.magaza_id = ? AND us.id = ?
        ");
        $stmt->execute([$magaza_id, $item['id']]);
        $stock_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stock_info) {
            if ($stock_info['magaza_stok'] < $item['miktar']) {
                $conflicts[] = [
                    'urun_id' => $item['id'],
                    'urun_adi' => $stock_info['urun_adi'],
                    'talep_edilen' => $item['miktar'],
                    'mevcut_stok' => $stock_info['magaza_stok'],
                    'eksik' => $item['miktar'] - $stock_info['magaza_stok'],
                    'type' => 'insufficient_stock'
                ];
            }
        }
    }
    
    return $conflicts;
}

/**
 * Çakışma kaydet
 */
function logConflict($conflict_type, $magaza_id, $conflict_data, $conn) {
    $stmt = $conn->prepare("
        INSERT INTO conflict_log (
            conflict_type, magaza_id, urun_id, conflict_data, created_at
        ) VALUES (?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $conflict_type,
        $magaza_id,
        $conflict_data['urun_id'] ?? null,
        json_encode($conflict_data)
    ]);
}

/**
 * Ana satış faturası kaydet
 */
function saveSaleInvoice($sale_data, $conn) {
    // Fatura seri/no ayırma
    $fatura_parts = explode('-', $sale_data['fatura_no']);
    $fatura_seri = isset($fatura_parts[0]) ? $fatura_parts[0] : 'POS';
    
    $stmt = $conn->prepare("
        INSERT INTO satis_faturalari (
            fatura_turu, magaza, fatura_seri, fatura_no, fatura_tarihi,
            toplam_tutar, personel, musteri_id, kdv_tutari, indirim_tutari,
            net_tutar, odeme_turu, islem_turu, kredi_karti_banka, aciklama,
            sync_durumu, sync_tarihi
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            1, NOW()
        )
    ");
    
    $stmt->execute([
        $sale_data['fatura_turu'] ?? 'perakende',
        $sale_data['magaza_id'],
        $fatura_seri,
        $sale_data['fatura_no'],
        $sale_data['fatura_tarihi'] ?? date('Y-m-d H:i:s'),
        $sale_data['toplam_tutar'] ?? 0,
        $sale_data['personel_id'] ?? null,
        $sale_data['customer_id'] ?? null,
        $sale_data['kdv_tutari'] ?? 0,
        $sale_data['indirim_tutari'] ?? 0,
        $sale_data['net_tutar'] ?? $sale_data['toplam_tutar'],
        $sale_data['odeme_yontemi'] ?? 'nakit',
        $sale_data['islem_turu'] ?? 'satis',
        $sale_data['kredi_karti_banka'] ?? null,
        $sale_data['aciklama'] ?? 'Mağazadan sync edildi'
    ]);
    
    return $conn->lastInsertId();
}

/**
 * Satış detayı kaydet
 */
function saveSaleDetail($invoice_id, $item, $conn) {
    $stmt = $conn->prepare("
        INSERT INTO satis_fatura_detay (
            fatura_id, urun_id, miktar, birim_fiyat,
            kdv_orani, indirim_orani, toplam_tutar
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $invoice_id,
        $item['id'],
        $item['miktar'],
        $item['birim_fiyat'],
        $item['kdv_orani'] ?? 0,
        $item['indirim_orani'] ?? 0,
        $item['toplam']
    ]);
}

/**
 * Stok hareketi ekle
 */
function addStockMovementForSale($item, $sale_data, $conn) {
    $movement_params = [
        'urun_id' => $item['id'],
        'miktar' => $item['miktar'] * -1, // Çıkış
        'hareket_tipi' => 'cikis',
        'aciklama' => 'Mağaza satışı - ' . $sale_data['fatura_no'],
        'belge_no' => $sale_data['fatura_no'],
        'tarih' => date('Y-m-d H:i:s'),
        'kullanici_id' => null, // Mağaza satışı
        'magaza_id' => $sale_data['magaza_id'],
        'satis_fiyati' => $item['birim_fiyat']
    ];
    
    addStockMovement($movement_params, $conn);
}

/**
 * Müşteri işlemleri - ⭐ MERKEZİ
 */
function processCustomerOperations($invoice_id, $sale_data, $conn) {
    $customer_id = $sale_data['customer_id'];
    
    // Puan kullanımı
    if (isset($sale_data['kullanilan_puan']) && $sale_data['kullanilan_puan'] > 0) {
        $stmt = $conn->prepare("
            INSERT INTO puan_harcama (fatura_id, musteri_id, harcanan_puan, tarih)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$invoice_id, $customer_id, $sale_data['kullanilan_puan']]);
        
        // Puan bakiyesini güncelle
        $stmt = $conn->prepare("
            UPDATE musteri_puanlar 
            SET puan_bakiye = puan_bakiye - ?, son_alisveris_tarihi = NOW()
            WHERE musteri_id = ?
        ");
        $stmt->execute([$sale_data['kullanilan_puan'], $customer_id]);
    }
    
    // Puan kazanma
    if (isset($sale_data['kazanilan_puan']) && $sale_data['kazanilan_puan'] > 0) {
        $stmt = $conn->prepare("
            INSERT INTO puan_kazanma (fatura_id, musteri_id, kazanilan_puan, odeme_tutari, tarih)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $invoice_id, 
            $customer_id, 
            $sale_data['kazanilan_puan'],
            $sale_data['net_tutar']
        ]);
        
        // Puan bakiyesini güncelle
        $stmt = $conn->prepare("
            UPDATE musteri_puanlar 
            SET puan_bakiye = puan_bakiye + ?, son_alisveris_tarihi = NOW()
            WHERE musteri_id = ?
        ");
        $stmt->execute([$sale_data['kazanilan_puan'], $customer_id]);
    }
    
    // ⭐ YENİ - Müşteri son alışveriş tarihini güncelle
    $stmt = $conn->prepare("
        UPDATE musteriler 
        SET last_updated = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$customer_id]);
}

/**
 * ⭐ YENİ - Borç kaydı işle (MERKEZİ)
 */
function processDebtRecord($invoice_id, $sale_data, $conn) {
    if (!isset($sale_data['customer_id'])) {
        return null; // Borç için müşteri gerekli
    }
    
    $stmt = $conn->prepare("
        INSERT INTO musteri_borclar (
            musteri_id, toplam_tutar, indirim_tutari, borc_tarihi, 
            fis_no, magaza_id, odendi_mi, olusturma_tarihi
        ) VALUES (?, ?, ?, NOW(), ?, ?, 0, NOW())
    ");
    
    $stmt->execute([
        $sale_data['customer_id'],
        $sale_data['toplam_tutar'] ?? $sale_data['net_tutar'],
        $sale_data['indirim_tutari'] ?? 0,
        $sale_data['fatura_no'],
        $sale_data['magaza_id']
    ]);
    
    $borc_id = $conn->lastInsertId();
    
    // Borç detayları
    foreach ($sale_data['items'] as $item) {
        $stmt = $conn->prepare("
            INSERT INTO musteri_borc_detaylar (
                borc_id, urun_adi, miktar, tutar, urun_id, olusturma_tarihi
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $borc_id,
            $item['ad'],
            $item['miktar'],
            $item['toplam'],
            $item['id']
        ]);
    }
    
    return $borc_id;
}

/**
 * Sync metadata kaydet
 */
function saveSyncMetadata($magaza_id, $table_name, $sync_metadata, $conn) {
    $stmt = $conn->prepare("
        INSERT INTO sync_metadata (
            magaza_id, tablo_adi, son_sync_tarihi, sync_durumu, operation_count
        ) VALUES (?, ?, NOW(), 'basarili', 1)
        ON DUPLICATE KEY UPDATE
        son_sync_tarihi = NOW(),
        sync_durumu = 'basarili',
        operation_count = operation_count + 1
    ");
    
    $stmt->execute([$magaza_id, $table_name]);
}

// ==================== ⭐ YENİ - WEBHOOK FONKSİYONLARI ====================

/**
 * ⭐ YENİ - Fatura webhook tetikle (MERKEZİ)
 */
function triggerInvoiceWebhook($invoice_id, $action, $source_magaza_id) {
    // distribute_data.php API'sine webhook isteği gönder
    $webhook_url = 'https://pos.incikirtasiye.com/admin/api/sync/distribute_data.php';
    
    $payload = [
        'action' => $action,
        'data' => [
            'invoice_id' => $invoice_id,
            'source_store' => $source_magaza_id
        ],
        'target_stores' => 'all' // Tüm mağazalara dağıt (Dolunay hariç - o direct mode)
    ];
    
    return sendInternalWebhook($webhook_url, $payload);
}

/**
 * ⭐ YENİ - Müşteri webhook tetikle (MERKEZİ)
 */
function triggerCustomerWebhook($customer_id, $action, $source_magaza_id) {
    $webhook_url = 'https://pos.incikirtasiye.com/admin/api/sync/distribute_data.php';
    
    $payload = [
        'action' => $action,
        'data' => [
            'customer_id' => $customer_id,
            'source_store' => $source_magaza_id
        ],
        'target_stores' => 'all'
    ];
    
    return sendInternalWebhook($webhook_url, $payload);
}

/**
 * ⭐ YENİ - Borç webhook tetikle (MERKEZİ)
 */
function triggerCreditWebhook($credit_id, $action, $source_magaza_id) {
    $webhook_url = 'https://pos.incikirtasiye.com/admin/api/sync/distribute_data.php';
    
    $payload = [
        'action' => $action,
        'data' => [
            'credit_id' => $credit_id,
            'source_store' => $source_magaza_id
        ],
        'target_stores' => 'all'
    ];
    
    return sendInternalWebhook($webhook_url, $payload);
}

/**
 * ⭐ YENİ - İç webhook gönder
 */
function sendInternalWebhook($url, $payload) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Internal-Request: true',
            'User-Agent: POS-Internal-Webhook/1.0'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false, // Internal request
        CURLOPT_FOLLOWLOCATION => false
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Internal webhook hatası ($url): $error");
        return ['success' => false, 'message' => $error];
    }
    
    if ($http_code !== 200) {
        error_log("Internal webhook HTTP hatası ($url): HTTP $http_code");
        return ['success' => false, 'message' => "HTTP $http_code"];
    }
    
    $response_data = json_decode($response, true);
    return $response_data ?: ['success' => true, 'message' => 'Webhook sent'];
}
?>
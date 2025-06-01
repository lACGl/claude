/**
 * POS Hibrit Sync Manager v2.1
 * -----------------------------
 * Merkez mağaza için Local DB + Ana Sunucu senkronizasyon yöneticisi
 * Dolunay mağaza direct mode destekli
 */

class POSSyncManager {
    constructor() {
        this.config = {
            storeId: window.STORE_ID || 1, // config.php'den
            syncMode: window.SYNC_MODE || 'SYNC', // SYNC veya DIRECT
            baseUrl: window.SYNC_BASE_URL || '/admin/api/local_sync/',
            retryAttempts: 3,
            retryDelay: 2000, // 2 saniye
            maxQueueSize: 100,
            healthCheckInterval: 30000, // 30 saniye
            syncInterval: 60000 // 1 dakika
        };
        
        this.syncQueue = [];
        this.isOnline = navigator.onLine;
        this.isSyncing = false;
        this.lastSyncTime = null;
        this.syncStats = {
            successful: 0,
            failed: 0,
            pending: 0
        };
        
        this.init();
    }
    
    /**
     * Sync Manager başlatma
     */
    init() {
        console.log(`🚀 POS Sync Manager v2.1 başlatılıyor - Mod: ${this.config.syncMode}, Mağaza: ${this.config.storeId}`);
        
        // Sadece SYNC modunda çalış
        if (this.config.syncMode !== 'SYNC') {
            console.log('⚠️ Direct mode tespit edildi, sync manager pasif');
            return;
        }
        
        this.setupEventListeners();
        this.startHealthCheck();
        this.startPeriodicSync();
        this.loadPendingQueue();
        
        // Başlangıç sync'i
        setTimeout(() => {
            this.performFullSync();
        }, 2000);
    }
    
    /**
     * Event listener'ları kur
     */
    setupEventListeners() {
        // Online/offline durumu
        window.addEventListener('online', () => {
            console.log('🟢 İnternet bağlantısı tekrar kuruldu');
            this.isOnline = true;
            this.processQueue();
        });
        
        window.addEventListener('offline', () => {
            console.log('🔴 İnternet bağlantısı kesildi');
            this.isOnline = false;
        });
        
        // Sayfa kapatılırken kuyruğu kaydet
        window.addEventListener('beforeunload', () => {
            this.savePendingQueue();
        });
        
        // POS sistem olaylarını dinle
        this.setupPOSEventListeners();
    }
    
    /**
     * POS sistem olaylarını dinle
     */
    setupPOSEventListeners() {
        // Satış tamamlandığında
        $(document).on('sale_completed', (event, saleData) => {
            console.log('💰 Satış tamamlandı, sync kuyruğuna ekleniyor:', saleData.invoiceId);
            this.addToQueue('sale', saleData, 'high');
        });
        
        // Stok değiştiğinde
        $(document).on('stock_updated', (event, stockData) => {
            console.log('📦 Stok güncellendi, sync kuyruğuna ekleniyor:', stockData.productId);
            this.addToQueue('stock_update', stockData, 'high');
        });
        
        // Müşteri işlemleri
        $(document).on('customer_updated', (event, customerData) => {
            console.log('👤 Müşteri güncellendi, sync kuyruğuna ekleniyor:', customerData.customerId);
            this.addToQueue('customer_update', customerData, 'medium');
        });
        
        // Borç ödemeleri - YENİ
        $(document).on('debt_payment', (event, paymentData) => {
            console.log('💳 Borç ödeme, sync kuyruğuna ekleniyor:', paymentData.paymentId);
            this.addToQueue('debt_payment', paymentData, 'high');
        });
    }
    
    /**
     * Kuyruğa öğe ekle
     */
    addToQueue(operation, data, priority = 'medium') {
        if (this.syncQueue.length >= this.config.maxQueueSize) {
            console.warn('⚠️ Sync kuyruğu dolu, eski öğeler siliniyor');
            this.syncQueue.shift();
        }
        
        const queueItem = {
            id: this.generateId(),
            operation,
            data,
            priority,
            attempts: 0,
            createdAt: new Date().toISOString(),
            status: 'pending'
        };
        
        // Önceliğe göre sıralama
        if (priority === 'high') {
            this.syncQueue.unshift(queueItem);
        } else {
            this.syncQueue.push(queueItem);
        }
        
        this.updateSyncStats();
        
        // Online ise hemen işle
        if (this.isOnline && !this.isSyncing) {
            this.processQueue();
        }
    }
    
    /**
     * Kuyruğu işle
     */
    async processQueue() {
        if (this.isSyncing || !this.isOnline || this.syncQueue.length === 0) {
            return;
        }
        
        this.isSyncing = true;
        console.log(`🔄 Kuyruk işleniyor: ${this.syncQueue.length} öğe`);
        
        const startTime = performance.now();
        let processed = 0;
        let successful = 0;
        
        // Kuyruktaki her öğeyi işle
        while (this.syncQueue.length > 0 && this.isOnline) {
            const item = this.syncQueue.shift();
            processed++;
            
            try {
                const result = await this.syncItem(item);
                if (result.success) {
                    successful++;
                    this.syncStats.successful++;
                    console.log(`✅ Sync başarılı: ${item.operation} (${item.id})`);
                } else {
                    throw new Error(result.message || 'Sync hatası');
                }
            } catch (error) {
                console.error(`❌ Sync hatası: ${item.operation} (${item.id}):`, error.message);
                
                // Yeniden deneme
                item.attempts++;
                if (item.attempts < this.config.retryAttempts) {
                    item.status = 'retry';
                    this.syncQueue.push(item); // Sona ekle
                    console.log(`🔄 Yeniden deneme: ${item.attempts}/${this.config.retryAttempts}`);
                } else {
                    item.status = 'failed';
                    this.syncStats.failed++;
                    console.error(`💀 Sync tamamen başarısız: ${item.operation} (${item.id})`);
                    this.handleFailedSync(item);
                }
            }
            
            // Küçük bir bekleme
            await this.delay(100);
        }
        
        const endTime = performance.now();
        const duration = Math.round(endTime - startTime);
        
        console.log(`🏁 Kuyruk işleme tamamlandı: ${successful}/${processed} başarılı (${duration}ms)`);
        
        this.lastSyncTime = new Date();
        this.isSyncing = false;
        this.updateSyncStats();
        this.updateSyncUI();
    }
    
    /**
     * Tek öğeyi sync et
     */
    async syncItem(item) {
        const endpoint = this.getEndpoint(item.operation);
        
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Store-ID': this.config.storeId,
                    'X-Sync-Key': this.generateSyncKey(item)
                },
                body: JSON.stringify({
                    operation: item.operation,
                    data: item.data,
                    metadata: {
                        itemId: item.id,
                        storeId: this.config.storeId,
                        timestamp: item.createdAt,
                        attempt: item.attempts + 1
                    }
                })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            return result;
            
        } catch (error) {
            throw new Error(`Sync API hatası: ${error.message}`);
        }
    }
    
    /**
     * Operasyon tipine göre endpoint döndür
     */
    getEndpoint(operation) {
        const endpoints = {
            'sale': this.config.baseUrl + 'send_sales.php',
            'stock_update': this.config.baseUrl + 'send_stock.php',
            'customer_update': this.config.baseUrl + 'send_customer.php',
            'debt_payment': this.config.baseUrl + 'send_payment.php'
        };
        
        return endpoints[operation] || endpoints['sale'];
    }
    
    /**
     * Sync key oluştur
     */
    generateSyncKey(item) {
        const data = `${item.id}:${item.operation}:${this.config.storeId}:${item.createdAt}`;
        return btoa(data).substring(0, 16);
    }
    
    /**
     * Başarısız sync işlemi ele al
     */
    handleFailedSync(item) {
        // Kritik veriler için local backup
        if (item.operation === 'sale' || item.operation === 'debt_payment') {
            this.saveToLocalBackup(item);
        }
        
        // UI'da hata göster
        this.showSyncError(item);
    }
    
    /**
     * Local backup'a kaydet
     */
    saveToLocalBackup(item) {
        try {
            const backup = JSON.parse(localStorage.getItem('pos_sync_backup') || '[]');
            backup.push({
                ...item,
                backupDate: new Date().toISOString()
            });
            
            // Maksimum 50 öğe sakla
            if (backup.length > 50) {
                backup.splice(0, backup.length - 50);
            }
            
            localStorage.setItem('pos_sync_backup', JSON.stringify(backup));
            console.log('💾 Başarısız sync local backup\'a kaydedildi');
        } catch (error) {
            console.error('Local backup hatası:', error);
        }
    }
    
    /**
     * Periyodik sync başlat
     */
    startPeriodicSync() {
        setInterval(() => {
            if (this.isOnline && this.syncQueue.length > 0) {
                this.processQueue();
            }
        }, this.config.syncInterval);
        
        // 5 dakikada bir full sync
        setInterval(() => {
            if (this.isOnline) {
                this.performFullSync();
            }
        }, 300000); // 5 dakika
    }
    
    /**
     * Health check başlat
     */
    startHealthCheck() {
        setInterval(async () => {
            await this.checkSystemHealth();
        }, this.config.healthCheckInterval);
    }
    
    /**
     * Sistem sağlığını kontrol et
     */
    async checkSystemHealth() {
        try {
            const response = await fetch(this.config.baseUrl + 'sync_status_check.php', {
                method: 'GET',
                headers: {
                    'X-Store-ID': this.config.storeId
                }
            });
            
            if (response.ok) {
                const health = await response.json();
                this.updateHealthStatus(health);
            } else {
                this.updateHealthStatus({ status: 'error', message: 'API yanıt vermiyor' });
            }
        } catch (error) {
            console.warn('Health check hatası:', error.message);
            this.updateHealthStatus({ status: 'offline', message: error.message });
        }
    }
    
    /**
     * Full sync gerçekleştir
     */
    async performFullSync() {
        if (this.isSyncing) {
            return;
        }
        
        console.log('🔄 Full sync başlatılıyor...');
        
        try {
            // Pending verileri al
            const response = await fetch(this.config.baseUrl + 'get_pending_sync.php', {
                method: 'GET',
                headers: {
                    'X-Store-ID': this.config.storeId
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.pending && data.pending.length > 0) {
                    console.log(`📨 ${data.pending.length} adet bekleyen veri tespit edildi`);
                    
                    data.pending.forEach(item => {
                        this.addToQueue(item.operation, item.data, 'medium');
                    });
                }
            }
        } catch (error) {
            console.error('Full sync hatası:', error);
        }
    }
    
    /**
     * Sync istatistiklerini güncelle
     */
    updateSyncStats() {
        this.syncStats.pending = this.syncQueue.length;
        
        // Event gönder
        $(document).trigger('sync_stats_updated', [this.syncStats]);
    }
    
    /**
     * Sync UI'ını güncelle
     */
    updateSyncUI() {
        const syncStatus = this.getSyncStatus();
        
        // Sync status göstergesi
        const $syncIndicator = $('#syncStatus');
        if ($syncIndicator.length) {
            $syncIndicator.removeClass('sync-online sync-offline sync-syncing sync-error')
                         .addClass(`sync-${syncStatus.status}`)
                         .attr('title', syncStatus.message);
        }
        
        // Son sync zamanı
        const $lastSync = $('#lastSyncTime');
        if ($lastSync.length && this.lastSyncTime) {
            $lastSync.text(this.formatSyncTime(this.lastSyncTime));
        }
        
        // Kuyruk sayısı
        const $queueCount = $('#syncQueueCount');
        if ($queueCount.length) {
            $queueCount.text(this.syncQueue.length);
            $queueCount.toggle(this.syncQueue.length > 0);
        }
    }
    
    /**
     * Sync durumunu al
     */
    getSyncStatus() {
        if (!this.isOnline) {
            return { status: 'offline', message: 'İnternet bağlantısı yok' };
        }
        
        if (this.isSyncing) {
            return { status: 'syncing', message: 'Senkronizasyon devam ediyor...' };
        }
        
        if (this.syncQueue.length > 0) {
            return { status: 'pending', message: `${this.syncQueue.length} öğe bekliyor` };
        }
        
        if (this.syncStats.failed > 0) {
            return { status: 'error', message: `${this.syncStats.failed} hata var` };
        }
        
        return { status: 'online', message: 'Tüm veriler senkronize' };
    }
    
    /**
     * Health status güncelle
     */
    updateHealthStatus(health) {
        // Health bilgilerini kaydet
        this.lastHealthCheck = {
            timestamp: new Date(),
            status: health.status,
            message: health.message
        };
        
        // Event gönder
        $(document).trigger('health_status_updated', [health]);
    }
    
    /**
     * Sync hatası göster
     */
    showSyncError(item) {
        if (typeof showToast === 'function') {
            showToast(`Sync hatası: ${item.operation} (${item.attempts} deneme)`, 'error');
        }
    }
    
    /**
     * Bekleyen kuyruğu kaydet
     */
    savePendingQueue() {
        try {
            localStorage.setItem('pos_sync_queue', JSON.stringify(this.syncQueue));
        } catch (error) {
            console.error('Kuyruk kaydetme hatası:', error);
        }
    }
    
    /**
     * Bekleyen kuyruğu yükle
     */
    loadPendingQueue() {
        try {
            const saved = localStorage.getItem('pos_sync_queue');
            if (saved) {
                this.syncQueue = JSON.parse(saved);
                console.log(`📂 ${this.syncQueue.length} adet bekleyen sync yüklendi`);
                localStorage.removeItem('pos_sync_queue');
            }
        } catch (error) {
            console.error('Kuyruk yükleme hatası:', error);
            this.syncQueue = [];
        }
    }
    
    /**
     * Sync zamanını formatla
     */
    formatSyncTime(date) {
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) { // 1 dakikadan az
            return 'Az önce';
        } else if (diff < 3600000) { // 1 saatten az
            return `${Math.floor(diff / 60000)} dk önce`;
        } else {
            return date.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
        }
    }
    
    /**
     * Yardımcı fonksiyonlar
     */
    generateId() {
        return 'sync_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
    
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    
    /**
     * Manuel sync tetikleme
     */
    async forceSyncNow() {
        console.log('🚀 Manuel sync tetiklendi');
        
        if (this.isOnline) {
            await this.performFullSync();
            await this.processQueue();
        } else {
            if (typeof showToast === 'function') {
                showToast('İnternet bağlantısı olmadan sync yapılamaz', 'error');
            }
        }
    }
    
    /**
     * Debug bilgileri
     */
    getDebugInfo() {
        return {
            config: this.config,
            syncStats: this.syncStats,
            queueLength: this.syncQueue.length,
            isOnline: this.isOnline,
            isSyncing: this.isSyncing,
            lastSyncTime: this.lastSyncTime,
            lastHealthCheck: this.lastHealthCheck
        };
    }
}

// Global sync manager instance
window.POSSyncManager = null;

// Sayfa yüklendiğinde sync manager'ı başlat
$(document).ready(function() {
    // Sadece gerekli config değişkenleri varsa başlat
    if (typeof window.STORE_ID !== 'undefined' && typeof window.SYNC_MODE !== 'undefined') {
        window.POSSyncManager = new POSSyncManager();
        
        // Global erişim için
        window.forceSyncNow = () => {
            if (window.POSSyncManager) {
                window.POSSyncManager.forceSyncNow();
            }
        };
        
        // Debug için
        window.getSyncDebugInfo = () => {
            return window.POSSyncManager ? window.POSSyncManager.getDebugInfo() : null;
        };
        
        console.log('✅ POS Sync Manager hazır');
    } else {
        console.warn('⚠️ Sync config değişkenleri bulunamadı, sync manager başlatılmadı');
    }
});

// Sync status göstergesi için CSS
const syncStatusCSS = `
<style>
#syncStatus {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-left: 8px;
    transition: all 0.3s ease;
}

#syncStatus.sync-online { background-color: #10b981; }
#syncStatus.sync-offline { background-color: #ef4444; }
#syncStatus.sync-syncing { 
    background-color: #3b82f6; 
    animation: pulse 1s infinite;
}
#syncStatus.sync-pending { background-color: #f59e0b; }
#syncStatus.sync-error { background-color: #dc2626; }

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

#syncQueueCount {
    background-color: #ef4444;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 10px;
    margin-left: 4px;
}
</style>
`;

$('head').append(syncStatusCSS);
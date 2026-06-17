/// Application-wide constants for the PMP mobile app.
class AppConstants {
  AppConstants._();

  static const String appName = 'PMP Supplier Portal';

  // API
  static const String apiBaseUrl = 'https://api.pmp.example.com/api/v1';
  static const Duration connectTimeout = Duration(seconds: 10);
  static const Duration receiveTimeout = Duration(seconds: 30);

  // Hive box names
  static const String authBoxName = 'auth_box';
  static const String dashboardBoxName = 'dashboard_box';
  static const String tendersBoxName = 'tenders_box';
  static const String purchaseOrdersBoxName = 'purchase_orders_box';
  static const String invoicesBoxName = 'invoices_box';
  static const String notificationsBoxName = 'notifications_box';
  static const String pendingOpsBoxName = 'pending_ops_box';

  // Aliases used by HiveCacheService (typed Hive boxes with CacheEntry)
  static const String tenderBoxName = tendersBoxName;
  static const String purchaseOrderBoxName = purchaseOrdersBoxName;
  static const String invoiceBoxName = invoicesBoxName;
  static const String notificationBoxName = notificationsBoxName;

  // Aliases used by widget_test.dart and HiveCacheManager
  static const String hiveBoxAuth = authBoxName;
  static const String hiveBoxTenders = tendersBoxName;
  static const String hiveBoxPurchaseOrders = purchaseOrdersBoxName;
  static const String hiveBoxInvoices = invoicesBoxName;
  static const String hiveBoxDashboard = dashboardBoxName;
  static const String hiveBoxNotifications = notificationsBoxName;
  static const String hiveBoxOfflineQueue = pendingOpsBoxName;

  // Aliases used by CacheService (meta box)
  static const String hiveCacheMetaBox = 'cache_meta_box';

  // Aliases used by WriteQueueService
  static const String hiveWriteQueueBox = 'write_queue_box';

  // Aliases used by PendingWritesQueue
  static const String pendingWritesBox = pendingOpsBoxName;

  // Aliases used by HiveCacheService / hive_init
  static const String hiveBoxTenderDetail = 'tender_detail_box';
  static const String hiveBoxPurchaseOrderDetail = 'purchase_order_detail_box';

  // Cache TTL
  static const Duration listCacheTtl = Duration(hours: 24);
  static const Duration detailCacheTtl = Duration(hours: 1);

  // Secure storage keys
  static const String accessTokenKey = 'access_token';
  static const String refreshTokenKey = 'refresh_token';
  static const String tenantIdKey = 'tenant_id';
  static const String userIdKey = 'user_id';

  // Pagination
  static const int defaultPageSize = 20;
}

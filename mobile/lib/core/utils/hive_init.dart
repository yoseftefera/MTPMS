import 'package:hive_flutter/hive_flutter.dart';
import '../constants/app_constants.dart';

/// Initialises Hive and opens all application boxes.
///
/// Call this once from [main] before [runApp].
Future<void> initHive() async {
  await Hive.initFlutter();

  // Open all boxes as typed Map boxes.
  await Future.wait([
    Hive.openBox<Map>(AppConstants.hiveBoxAuth),
    Hive.openBox<Map>(AppConstants.hiveBoxDashboard),
    Hive.openBox<Map>(AppConstants.hiveBoxTenders),
    Hive.openBox<Map>(AppConstants.hiveBoxTenderDetail),
    Hive.openBox<Map>(AppConstants.hiveBoxPurchaseOrders),
    Hive.openBox<Map>(AppConstants.hiveBoxPurchaseOrderDetail),
    Hive.openBox<Map>(AppConstants.hiveBoxInvoices),
    Hive.openBox<Map>(AppConstants.hiveBoxNotifications),
    Hive.openBox<Map>(AppConstants.hiveBoxOfflineQueue),
    Hive.openBox<String>(AppConstants.hiveCacheMetaBox),
    Hive.openBox<String>(AppConstants.hiveWriteQueueBox),
  ]);
}

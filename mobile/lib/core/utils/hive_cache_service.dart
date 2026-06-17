import 'package:hive_flutter/hive_flutter.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../constants/app_constants.dart';
import '../errors/exceptions.dart';
import 'cache_entry.dart';

/// Thin wrapper around Hive that enforces TTL-based cache invalidation.
///
/// - List data  → 24-hour TTL ([AppConstants.listCacheTtl])
/// - Detail data → 1-hour TTL  ([AppConstants.detailCacheTtl])
///
/// All values are stored as [Map] so no Hive TypeAdapters are required.
class HiveCacheService {
  HiveCacheService._();

  static final HiveCacheService instance = HiveCacheService._();

  // ---------------------------------------------------------------------------
  // Initialisation
  // ---------------------------------------------------------------------------

  /// Must be called once at app startup (before [runApp]).
  static Future<void> init() async {
    await Hive.initFlutter();

    // Open all boxes up-front so they are ready when needed.
    await Future.wait([
      Hive.openBox<Map>(AppConstants.hiveBoxAuth),
      Hive.openBox<Map>(AppConstants.hiveBoxTenders),
      Hive.openBox<Map>(AppConstants.hiveBoxTenderDetail),
      Hive.openBox<Map>(AppConstants.hiveBoxPurchaseOrders),
      Hive.openBox<Map>(AppConstants.hiveBoxPurchaseOrderDetail),
      Hive.openBox<Map>(AppConstants.hiveBoxDashboard),
      Hive.openBox<Map>(AppConstants.hiveBoxInvoices),
      Hive.openBox<Map>(AppConstants.hiveBoxNotifications),
      Hive.openBox<Map>(AppConstants.hiveBoxOfflineQueue),
    ]);
  }

  // ---------------------------------------------------------------------------
  // Write
  // ---------------------------------------------------------------------------

  /// Stores [value] under [key] in [boxName] with the given [ttl].
  Future<void> put({
    required String boxName,
    required String key,
    required dynamic value,
    Duration ttl = AppConstants.listCacheTtl,
  }) async {
    final box = Hive.box<Map>(boxName);
    final entry = CacheEntry(
      data: value,
      expiresAt: DateTime.now().toUtc().add(ttl),
    );
    await box.put(key, entry.toMap());
  }

  /// Convenience method for list data (24-hour TTL).
  Future<void> putList({
    required String boxName,
    required String key,
    required dynamic value,
  }) =>
      put(
        boxName: boxName,
        key: key,
        value: value,
        ttl: AppConstants.listCacheTtl,
      );

  /// Convenience method for detail data (1-hour TTL).
  Future<void> putDetail({
    required String boxName,
    required String key,
    required dynamic value,
  }) =>
      put(
        boxName: boxName,
        key: key,
        value: value,
        ttl: AppConstants.detailCacheTtl,
      );

  // ---------------------------------------------------------------------------
  // Read
  // ---------------------------------------------------------------------------

  /// Returns the cached value for [key] in [boxName].
  ///
  /// Throws [CacheException] if the key is absent.
  /// Throws [CacheExpiredException] if the entry has exceeded its TTL.
  dynamic get({required String boxName, required String key}) {
    final box = Hive.box<Map>(boxName);
    final raw = box.get(key);

    if (raw == null)
      throw CacheException(message: 'No cache entry for key "$key".');

    final entry = CacheEntry.fromMap(raw);
    if (entry.isExpired)
      throw CacheExpiredException(message: 'Cache entry "$key" has expired.');

    return entry.data;
  }

  /// Returns the cached value or `null` if absent / expired (no throw).
  dynamic getOrNull({required String boxName, required String key}) {
    try {
      return get(boxName: boxName, key: key);
    } on AppException {
      return null;
    }
  }

  // ---------------------------------------------------------------------------
  // Invalidation
  // ---------------------------------------------------------------------------

  Future<void> delete({required String boxName, required String key}) async {
    final box = Hive.box<Map>(boxName);
    await box.delete(key);
  }

  Future<void> clearBox(String boxName) async {
    final box = Hive.box<Map>(boxName);
    await box.clear();
  }

  Future<void> clearAll() async {
    for (final name in [
      AppConstants.hiveBoxTenders,
      AppConstants.hiveBoxTenderDetail,
      AppConstants.hiveBoxPurchaseOrders,
      AppConstants.hiveBoxPurchaseOrderDetail,
      AppConstants.hiveBoxDashboard,
      AppConstants.hiveBoxInvoices,
      AppConstants.hiveBoxNotifications,
    ]) {
      await clearBox(name);
    }
  }
}

/// Riverpod provider for [HiveCacheService].
final hiveCacheServiceProvider = Provider<HiveCacheService>((ref) {
  return HiveCacheService.instance;
});

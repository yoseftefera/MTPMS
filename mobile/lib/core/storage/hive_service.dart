import 'package:hive_flutter/hive_flutter.dart';

import '../constants/app_constants.dart';

/// Initializes and manages all Hive boxes used for offline caching.
///
/// Box naming follows [AppConstants] box name constants.
/// Each box stores JSON-serializable maps keyed by a string identifier.
///
/// Cache TTL is enforced by storing a `_cachedAt` timestamp alongside each
/// entry and checking it on read:
///   - List data: 24-hour TTL ([AppConstants.listCacheTtl])
///   - Detail data: 1-hour TTL ([AppConstants.detailCacheTtl])
class HiveService {
  HiveService._();

  /// Opens all required Hive boxes. Call once at app startup.
  static Future<void> initialize() async {
    await Hive.initFlutter();

    await Future.wait([
      Hive.openBox<Map>(AppConstants.authBoxName),
      Hive.openBox<Map>(AppConstants.dashboardBoxName),
      Hive.openBox<Map>(AppConstants.tendersBoxName),
      Hive.openBox<Map>(AppConstants.purchaseOrdersBoxName),
      Hive.openBox<Map>(AppConstants.invoicesBoxName),
      Hive.openBox<Map>(AppConstants.notificationsBoxName),
      Hive.openBox<Map>(AppConstants.pendingOpsBoxName),
    ]);
  }

  // ---------------------------------------------------------------------------
  // Generic cache helpers
  // ---------------------------------------------------------------------------

  /// Writes [data] to [boxName] under [key], stamping the current UTC time.
  static Future<void> put(
    String boxName,
    String key,
    Map<String, dynamic> data,
  ) async {
    final box = Hive.box<Map>(boxName);
    await box.put(key, {
      ...data,
      '_cachedAt': DateTime.now().toUtc().toIso8601String(),
    });
  }

  /// Reads a cached entry from [boxName] under [key].
  ///
  /// Returns `null` if the entry does not exist or has exceeded [ttl].
  static Map<String, dynamic>? get(
    String boxName,
    String key, {
    required Duration ttl,
  }) {
    final box = Hive.box<Map>(boxName);
    final raw = box.get(key);
    if (raw == null) return null;

    final data = Map<String, dynamic>.from(raw);
    final cachedAtStr = data['_cachedAt'] as String?;
    if (cachedAtStr == null) return null;

    final cachedAt = DateTime.tryParse(cachedAtStr);
    if (cachedAt == null) return null;

    final age = DateTime.now().toUtc().difference(cachedAt);
    if (age > ttl) {
      // Entry has expired — remove it and return null.
      box.delete(key);
      return null;
    }

    return data;
  }

  /// Removes a single entry from [boxName] under [key].
  static Future<void> delete(String boxName, String key) async {
    final box = Hive.box<Map>(boxName);
    await box.delete(key);
  }

  /// Clears all entries from [boxName].
  static Future<void> clearBox(String boxName) async {
    final box = Hive.box<Map>(boxName);
    await box.clear();
  }

  /// Clears all cached data (used on logout).
  static Future<void> clearAll() async {
    await Future.wait([
      clearBox(AppConstants.authBoxName),
      clearBox(AppConstants.dashboardBoxName),
      clearBox(AppConstants.tendersBoxName),
      clearBox(AppConstants.purchaseOrdersBoxName),
      clearBox(AppConstants.invoicesBoxName),
      clearBox(AppConstants.notificationsBoxName),
      // Intentionally keep pendingOpsBox so queued writes survive logout.
    ]);
  }

  // ---------------------------------------------------------------------------
  // Convenience: list cache (24-hour TTL)
  // ---------------------------------------------------------------------------

  static Future<void> putList(String boxName, String key, Map<String, dynamic> data) =>
      put(boxName, key, data);

  static Map<String, dynamic>? getList(String boxName, String key) =>
      get(boxName, key, ttl: AppConstants.listCacheTtl);

  // ---------------------------------------------------------------------------
  // Convenience: detail cache (1-hour TTL)
  // ---------------------------------------------------------------------------

  static Future<void> putDetail(String boxName, String key, Map<String, dynamic> data) =>
      put(boxName, key, data);

  static Map<String, dynamic>? getDetail(String boxName, String key) =>
      get(boxName, key, ttl: AppConstants.detailCacheTtl);
}

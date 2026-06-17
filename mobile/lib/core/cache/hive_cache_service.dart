import 'dart:convert';

import 'package:hive_flutter/hive_flutter.dart';
import 'package:pmp_mobile/core/cache/cache_entry.dart';
import 'package:pmp_mobile/core/constants/app_constants.dart';
import 'package:pmp_mobile/core/errors/exceptions.dart';

/// Generic Hive-backed cache service with TTL support.
///
/// - List data uses [AppConstants.listCacheTtl]  (24 hours).
/// - Detail data uses [AppConstants.detailCacheTtl] (1 hour).
///
/// Keys are arbitrary strings; values are JSON-serialisable objects.
class HiveCacheService {
  HiveCacheService._();

  static final HiveCacheService instance = HiveCacheService._();

  // -------------------------------------------------------------------------
  // Initialisation
  // -------------------------------------------------------------------------

  /// Must be called once during app startup (after [Hive.initFlutter]).
  static Future<void> init() async {
    if (!Hive.isAdapterRegistered(0)) {
      Hive.registerAdapter(CacheEntryAdapter());
    }

    // Open all boxes up-front so they are ready before first use.
    await Future.wait([
      Hive.openBox<CacheEntry>(AppConstants.authBoxName),
      Hive.openBox<CacheEntry>(AppConstants.tenderBoxName),
      Hive.openBox<CacheEntry>(AppConstants.purchaseOrderBoxName),
      Hive.openBox<CacheEntry>(AppConstants.invoiceBoxName),
      Hive.openBox<CacheEntry>(AppConstants.dashboardBoxName),
      Hive.openBox<CacheEntry>(AppConstants.notificationBoxName),
      Hive.openBox<CacheEntry>(AppConstants.pendingOpsBoxName),
    ]);
  }

  // -------------------------------------------------------------------------
  // Public API
  // -------------------------------------------------------------------------

  /// Writes [value] (JSON-serialisable) to [boxName] under [key].
  Future<void> write({
    required String boxName,
    required String key,
    required dynamic value,
  }) async {
    final box = _box(boxName);
    final entry = CacheEntry(
      data: jsonEncode(value),
      cachedAt: DateTime.now().toUtc(),
    );
    await box.put(key, entry);
  }

  /// Reads the value stored under [key] in [boxName].
  ///
  /// Throws [CacheException] when:
  /// - the key does not exist, or
  /// - the entry has exceeded [ttl].
  dynamic read({
    required String boxName,
    required String key,
    required Duration ttl,
  }) {
    final box = _box(boxName);
    final entry = box.get(key);

    if (entry == null) {
      throw const CacheException(message: 'No cached data found.');
    }
    if (entry.isExpired(ttl)) {
      box.delete(key); // evict stale entry
      throw const CacheException(message: 'Cached data has expired.');
    }

    return jsonDecode(entry.data);
  }

  /// Reads a list from cache using the 24-hour list TTL.
  dynamic readList({required String boxName, required String key}) {
    return read(boxName: boxName, key: key, ttl: AppConstants.listCacheTtl);
  }

  /// Reads a detail record from cache using the 1-hour detail TTL.
  dynamic readDetail({required String boxName, required String key}) {
    return read(boxName: boxName, key: key, ttl: AppConstants.detailCacheTtl);
  }

  /// Removes a specific key from [boxName].
  Future<void> delete({required String boxName, required String key}) async {
    await _box(boxName).delete(key);
  }

  /// Clears all entries from [boxName].
  Future<void> clearBox(String boxName) async {
    await _box(boxName).clear();
  }

  /// Clears all cache boxes (e.g. on logout).
  Future<void> clearAll() async {
    await Future.wait([
      Hive.box<CacheEntry>(AppConstants.tenderBoxName).clear(),
      Hive.box<CacheEntry>(AppConstants.purchaseOrderBoxName).clear(),
      Hive.box<CacheEntry>(AppConstants.invoiceBoxName).clear(),
      Hive.box<CacheEntry>(AppConstants.dashboardBoxName).clear(),
      Hive.box<CacheEntry>(AppConstants.notificationBoxName).clear(),
      Hive.box<CacheEntry>(AppConstants.pendingOpsBoxName).clear(),
    ]);
  }

  // -------------------------------------------------------------------------
  // Private helpers
  // -------------------------------------------------------------------------

  Box<CacheEntry> _box(String name) {
    if (!Hive.isBoxOpen(name)) {
      throw StateError('Hive box "$name" is not open. Call HiveCacheService.init() first.');
    }
    return Hive.box<CacheEntry>(name);
  }
}

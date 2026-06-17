import 'package:hive_flutter/hive_flutter.dart';
import '../constants/app_constants.dart';
import '../errors/exceptions.dart';

/// A wrapper around Hive that enforces TTL-based cache expiry.
///
/// Each cached entry is stored as a map with two keys:
/// - `data`       – the serialised payload (JSON-compatible value).
/// - `cached_at`  – the UTC timestamp (milliseconds since epoch) when the
///                  entry was written.
///
/// On read, if `now - cached_at > ttl` the entry is deleted and `null` is
/// returned, forcing the caller to fetch fresh data from the network.
class HiveCacheManager {
  HiveCacheManager._();

  static final HiveCacheManager instance = HiveCacheManager._();

  // ---------------------------------------------------------------------------
  // Initialisation
  // ---------------------------------------------------------------------------

  /// Must be called once during app startup (before [runApp]).
  static Future<void> init() async {
    await Hive.initFlutter();
    await Future.wait([
      Hive.openBox<Map>(AppConstants.hiveBoxAuth),
      Hive.openBox<Map>(AppConstants.hiveBoxTenders),
      Hive.openBox<Map>(AppConstants.hiveBoxPurchaseOrders),
      Hive.openBox<Map>(AppConstants.hiveBoxInvoices),
      Hive.openBox<Map>(AppConstants.hiveBoxDashboard),
      Hive.openBox<Map>(AppConstants.hiveBoxNotifications),
      Hive.openBox<Map>(AppConstants.hiveBoxOfflineQueue),
    ]);
  }

  // ---------------------------------------------------------------------------
  // Read
  // ---------------------------------------------------------------------------

  /// Returns the cached value for [key] in [boxName] if it exists and has not
  /// expired according to [ttl].  Returns `null` otherwise.
  dynamic read({
    required String boxName,
    required String key,
    required Duration ttl,
  }) {
    try {
      final box = Hive.box<Map>(boxName);
      final entry = box.get(key);
      if (entry == null) return null;

      final cachedAt = entry['cached_at'] as int?;
      if (cachedAt == null) return null;

      final age = DateTime.now().millisecondsSinceEpoch - cachedAt;
      if (age > ttl.inMilliseconds) {
        box.delete(key);
        return null;
      }

      return entry['data'];
    } catch (e) {
      throw CacheException(message: 'Failed to read from cache: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Write
  // ---------------------------------------------------------------------------

  /// Writes [data] to [boxName] under [key], recording the current UTC time.
  Future<void> write({
    required String boxName,
    required String key,
    required dynamic data,
  }) async {
    try {
      final box = Hive.box<Map>(boxName);
      await box.put(key, {
        'data': data,
        'cached_at': DateTime.now().millisecondsSinceEpoch,
      });
    } catch (e) {
      throw CacheException(message: 'Failed to write to cache: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Delete
  // ---------------------------------------------------------------------------

  /// Removes a single entry from the cache.
  Future<void> delete({required String boxName, required String key}) async {
    try {
      final box = Hive.box<Map>(boxName);
      await box.delete(key);
    } catch (e) {
      throw CacheException(message: 'Failed to delete from cache: $e');
    }
  }

  /// Clears all entries in [boxName].
  Future<void> clearBox(String boxName) async {
    try {
      final box = Hive.box<Map>(boxName);
      await box.clear();
    } catch (e) {
      throw CacheException(message: 'Failed to clear cache box: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Offline write queue
  // ---------------------------------------------------------------------------

  /// Enqueues a write operation to be replayed when connectivity is restored.
  Future<void> enqueueOfflineOperation({
    required String id,
    required String method,
    required String path,
    required Map<String, dynamic> payload,
  }) async {
    try {
      final box = Hive.box<Map>(AppConstants.hiveBoxOfflineQueue);
      await box.put(id, {
        'id': id,
        'method': method,
        'path': path,
        'payload': payload,
        'queued_at': DateTime.now().millisecondsSinceEpoch,
      });
    } catch (e) {
      throw CacheException(message: 'Failed to enqueue offline operation: $e');
    }
  }

  /// Returns all pending offline operations ordered by `queued_at`.
  List<Map<String, dynamic>> getPendingOfflineOperations() {
    try {
      final box = Hive.box<Map>(AppConstants.hiveBoxOfflineQueue);
      final entries = box.values
          .map((e) => Map<String, dynamic>.from(e))
          .toList()
        ..sort(
            (a, b) => (a['queued_at'] as int).compareTo(b['queued_at'] as int));
      return entries;
    } catch (e) {
      throw CacheException(message: 'Failed to read offline queue: $e');
    }
  }

  /// Removes a successfully replayed operation from the queue.
  Future<void> removeOfflineOperation(String id) async {
    try {
      final box = Hive.box<Map>(AppConstants.hiveBoxOfflineQueue);
      await box.delete(id);
    } catch (e) {
      throw CacheException(message: 'Failed to remove offline operation: $e');
    }
  }
}

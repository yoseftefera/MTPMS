import 'package:hive/hive.dart';

import '../constants/app_constants.dart';
import 'cache_entry.dart';

/// Generic local cache backed by a Hive [Box].
///
/// Stores [CacheEntry] wrappers so TTL can be checked on read.
class LocalCache {
  final Box<Map> _box;
  final Duration _ttl;

  const LocalCache({required Box<Map> box, required Duration ttl})
      : _box = box,
        _ttl = ttl;

  // ---------------------------------------------------------------------------
  // Factory constructors for each cache type
  // ---------------------------------------------------------------------------

  /// Cache for list data — 24-hour TTL.
  static LocalCache listCache(Box<Map> box) =>
      LocalCache(box: box, ttl: AppConstants.listCacheTtl);

  /// Cache for detail data — 1-hour TTL.
  static LocalCache detailCache(Box<Map> box) =>
      LocalCache(box: box, ttl: AppConstants.detailCacheTtl);

  // ---------------------------------------------------------------------------
  // Read / Write
  // ---------------------------------------------------------------------------

  /// Writes [data] to the cache under [key].
  Future<void> put(String key, Map<String, dynamic> data) async {
    final entry = {
      'data': data,
      'cached_at': DateTime.now().toIso8601String(),
    };
    await _box.put(key, entry);
  }

  /// Returns the cached value for [key], or `null` if absent or expired.
  Map<String, dynamic>? get(String key) {
    final raw = _box.get(key);
    if (raw == null) return null;

    final cachedAt = DateTime.tryParse(raw['cached_at'] as String? ?? '');
    if (cachedAt == null) return null;

    final isExpired = DateTime.now().difference(cachedAt) > _ttl;
    if (isExpired) {
      _box.delete(key);
      return null;
    }

    return Map<String, dynamic>.from(raw['data'] as Map);
  }

  /// Returns `true` when a non-expired entry exists for [key].
  bool has(String key) => get(key) != null;

  /// Removes the entry for [key].
  Future<void> delete(String key) => _box.delete(key);

  /// Clears all entries in this cache.
  Future<void> clear() => _box.clear();
}

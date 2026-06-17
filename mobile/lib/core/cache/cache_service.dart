import 'dart:convert';

import 'package:hive/hive.dart';

import '../constants/app_constants.dart';
import '../errors/exceptions.dart';

/// Service for reading and writing cached data to Hive boxes.
///
/// Enforces TTL rules:
/// - List data: 24-hour TTL ([AppConstants.listCacheTtl])
/// - Detail data: 1-hour TTL ([AppConstants.detailCacheTtl])
///
/// Cache entries are stored as JSON strings. Metadata (timestamps) are stored
/// in a separate [AppConstants.hiveCacheMetaBox] box.
class CacheService {
  CacheService() {
    _metaBox = Hive.box<String>(AppConstants.hiveCacheMetaBox);
  }

  late final Box<String> _metaBox;

  // ---------------------------------------------------------------------------
  // Write
  // ---------------------------------------------------------------------------

  /// Caches [data] under [key] in [boxName] and records the current timestamp.
  Future<void> write({
    required String boxName,
    required String key,
    required dynamic data,
  }) async {
    try {
      final box = Hive.box<String>(boxName);
      final json = jsonEncode(data);
      await box.put(key, json);
      await _metaBox.put(
          _metaKey(boxName, key), DateTime.now().toIso8601String());
    } catch (e) {
      throw CacheException(message: 'Failed to write cache for key "$key": $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Read
  // ---------------------------------------------------------------------------

  /// Reads cached data for [key] from [boxName].
  ///
  /// Returns `null` if:
  /// - The key does not exist.
  /// - The cached entry has exceeded [ttl].
  T? read<T>({
    required String boxName,
    required String key,
    required Duration ttl,
  }) {
    try {
      if (!_isValid(boxName: boxName, key: key, ttl: ttl)) return null;

      final box = Hive.box<String>(boxName);
      final raw = box.get(key);
      if (raw == null) return null;

      return jsonDecode(raw) as T?;
    } catch (e) {
      throw CacheException(message: 'Failed to read cache for key "$key": $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Invalidate
  // ---------------------------------------------------------------------------

  /// Removes a single cache entry.
  Future<void> invalidate(
      {required String boxName, required String key}) async {
    try {
      final box = Hive.box<String>(boxName);
      await box.delete(key);
      await _metaBox.delete(_metaKey(boxName, key));
    } catch (e) {
      throw CacheException(
          message: 'Failed to invalidate cache for key "$key": $e');
    }
  }

  /// Clears all entries in [boxName].
  Future<void> clearBox(String boxName) async {
    try {
      final box = Hive.box<String>(boxName);
      await box.clear();
    } catch (e) {
      throw CacheException(message: 'Failed to clear cache box "$boxName": $e');
    }
  }

  // ---------------------------------------------------------------------------
  // TTL Check
  // ---------------------------------------------------------------------------

  bool _isValid({
    required String boxName,
    required String key,
    required Duration ttl,
  }) {
    final timestampStr = _metaBox.get(_metaKey(boxName, key));
    if (timestampStr == null) return false;

    final cachedAt = DateTime.tryParse(timestampStr);
    if (cachedAt == null) return false;

    return DateTime.now().difference(cachedAt) < ttl;
  }

  String _metaKey(String boxName, String key) => '$boxName::$key';
}

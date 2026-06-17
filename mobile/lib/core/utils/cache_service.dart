import 'dart:convert';

import 'package:hive_flutter/hive_flutter.dart';
import '../constants/app_constants.dart';
import '../errors/exceptions.dart';

/// A wrapper around Hive that adds TTL-based cache invalidation.
///
/// Each cached entry is stored as a JSON-encoded map containing:
/// - `data`: the serialised payload
/// - `cachedAt`: ISO-8601 timestamp of when the entry was written
///
/// On read, the entry is checked against the provided [ttl].  If the entry
/// has expired a [CacheException] is thrown so the caller can fall back
/// to a network request.
class CacheService {
  const CacheService();

  // ---------------------------------------------------------------------------
  // Write
  // ---------------------------------------------------------------------------

  /// Stores [data] under [key] in [boxName].
  Future<void> put(String boxName, String key, dynamic data) async {
    final box = await _openBox(boxName);
    final entry = jsonEncode({
      'data': data,
      'cachedAt': DateTime.now().toUtc().toIso8601String(),
    });
    await box.put(key, entry);
  }

  // ---------------------------------------------------------------------------
  // Read
  // ---------------------------------------------------------------------------

  /// Returns the cached value for [key] from [boxName] if it exists and has
  /// not exceeded [ttl].
  ///
  /// Throws [CacheException] when the key is absent or the entry has expired.
  Future<dynamic> get(
    String boxName,
    String key, {
    Duration ttl = AppConstants.listCacheTtl,
  }) async {
    final box = await _openBox(boxName);
    final raw = box.get(key);
    if (raw == null)
      throw const CacheException(message: 'No cached data found.');

    final entry = jsonDecode(raw) as Map<String, dynamic>;
    final cachedAt = DateTime.parse(entry['cachedAt'] as String);

    if (DateTime.now().toUtc().difference(cachedAt) > ttl) {
      await box.delete(key);
      throw const CacheException(message: 'Cached data has expired.');
    }

    return entry['data'];
  }

  // ---------------------------------------------------------------------------
  // Delete / clear
  // ---------------------------------------------------------------------------

  /// Removes a single entry.
  Future<void> delete(String boxName, String key) async {
    final box = await _openBox(boxName);
    await box.delete(key);
  }

  /// Removes all entries from a box.
  Future<void> clearBox(String boxName) async {
    final box = await _openBox(boxName);
    await box.clear();
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  Future<Box<String>> _openBox(String name) async {
    if (Hive.isBoxOpen(name)) {
      return Hive.box<String>(name);
    }
    return Hive.openBox<String>(name);
  }
}

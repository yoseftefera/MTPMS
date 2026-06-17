import 'package:hive/hive.dart';

part 'cache_entry.g.dart';

/// A Hive-persisted wrapper that stores a JSON-serialisable value together
/// with the timestamp at which it was written.  TTL enforcement is done at
/// read time by comparing [cachedAt] against the current clock.
@HiveType(typeId: 0)
class CacheEntry extends HiveObject {
  CacheEntry({
    required this.data,
    required this.cachedAt,
  });

  /// The serialised payload (JSON-encoded string).
  @HiveField(0)
  final String data;

  /// UTC timestamp when this entry was written.
  @HiveField(1)
  final DateTime cachedAt;

  /// Returns `true` when the entry is older than [ttl].
  bool isExpired(Duration ttl) {
    return DateTime.now().toUtc().difference(cachedAt) > ttl;
  }
}

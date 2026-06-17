/// Wraps a cached value with a timestamp so TTL can be enforced.
class CacheEntry<T> {
  final T data;
  final DateTime cachedAt;

  const CacheEntry({required this.data, required this.cachedAt});

  /// Returns `true` when the entry is older than [ttl].
  bool isExpired(Duration ttl) {
    return DateTime.now().difference(cachedAt) > ttl;
  }

  /// Serialises to a plain [Map] for Hive storage.
  Map<String, dynamic> toMap(Map<String, dynamic> Function(T) dataToMap) {
    return {
      'data': dataToMap(data),
      'cached_at': cachedAt.toIso8601String(),
    };
  }

  /// Deserialises from a plain [Map] read from Hive.
  static CacheEntry<T> fromMap<T>(
    Map<dynamic, dynamic> map,
    T Function(Map<String, dynamic>) dataFromMap,
  ) {
    return CacheEntry<T>(
      data: dataFromMap(Map<String, dynamic>.from(map['data'] as Map)),
      cachedAt: DateTime.parse(map['cached_at'] as String),
    );
  }
}

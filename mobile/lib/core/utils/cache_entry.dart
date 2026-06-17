/// A wrapper that stores a cached value together with its expiry timestamp.
///
/// Stored as a plain [Map] in Hive so no code generation is required.
class CacheEntry {
  const CacheEntry({
    required this.data,
    required this.expiresAt,
  });

  /// The serialised payload (typically a JSON-decoded [Map] or [List]).
  final dynamic data;

  /// UTC timestamp after which this entry is considered stale.
  final DateTime expiresAt;

  bool get isExpired => DateTime.now().toUtc().isAfter(expiresAt);

  // ---------------------------------------------------------------------------
  // Hive serialisation (manual – no code generation needed)
  // ---------------------------------------------------------------------------

  Map<String, dynamic> toMap() => {
        'data': data,
        'expiresAt': expiresAt.toIso8601String(),
      };

  factory CacheEntry.fromMap(Map<dynamic, dynamic> map) => CacheEntry(
        data: map['data'],
        expiresAt: DateTime.parse(map['expiresAt'] as String),
      );
}

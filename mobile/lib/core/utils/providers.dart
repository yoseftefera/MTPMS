// Re-export canonical providers to avoid duplication.
// The authoritative definitions are in core/network/api_client.dart and
// core/network/connectivity_service.dart.
export '../network/api_client.dart' show apiClientProvider, dioProvider;
export '../network/connectivity_service.dart'
    show connectivityServiceProvider, isOnlineProvider;
export 'hive_cache_service.dart' show hiveCacheServiceProvider;

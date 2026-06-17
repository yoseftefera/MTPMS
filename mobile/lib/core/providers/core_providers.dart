import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../cache/cache_service.dart';
import '../network/write_queue_service.dart';

// Re-export canonical providers so feature layers can import from one place.
export '../network/api_client.dart'
    show
        apiClientProvider,
        dioProvider,
        authInterceptorProvider,
        tenantInterceptorProvider;
export '../network/network_info.dart'
    show networkInfoProvider, isOnlineProvider, connectivityProvider;
export '../network/connectivity_provider.dart' show connectivityStreamProvider;

// ---------------------------------------------------------------------------
// Cache Service
// ---------------------------------------------------------------------------

final cacheServiceProvider = Provider<CacheService>((ref) {
  return CacheService();
});

// ---------------------------------------------------------------------------
// Write Queue Service
// ---------------------------------------------------------------------------

final writeQueueServiceProvider = Provider<WriteQueueService>((ref) {
  return WriteQueueService();
});

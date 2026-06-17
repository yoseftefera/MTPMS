import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../cache/hive_cache_manager.dart';

// Re-export so feature layers can import from a single location.
export 'api_client.dart' show apiClientProvider, dioProvider;
export 'connectivity_provider.dart' show connectivityStreamProvider;
export 'network_info.dart' show networkInfoProvider, isOnlineProvider;

/// Provides the singleton [HiveCacheManager].
final hiveCacheManagerProvider = Provider<HiveCacheManager>(
  (_) => HiveCacheManager.instance,
);

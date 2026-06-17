import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:logger/logger.dart';

import '../storage/pending_writes_queue.dart';
import 'api_client.dart';
import 'network_info.dart';

/// Listens for connectivity changes and replays queued write operations
/// when the device comes back online.
///
/// Satisfies Requirement 22.9: "write operation queue with sync on reconnect".
class SyncService {
  final ApiClient _apiClient;
  final PendingWritesQueue _queue;
  final NetworkInfo _networkInfo;
  final _logger = Logger();

  SyncService({
    required ApiClient apiClient,
    required PendingWritesQueue queue,
    required NetworkInfo networkInfo,
  })  : _apiClient = apiClient,
        _queue = queue,
        _networkInfo = networkInfo;

  /// Starts listening for connectivity changes and triggers sync on reconnect.
  void startListening() {
    _networkInfo.onConnectivityChanged.listen((isOnline) async {
      if (isOnline && !_queue.isEmpty) {
        await flush();
      }
    });
  }

  /// Replays all pending write operations in order.
  Future<void> flush() async {
    final pending = _queue.getAll();
    _logger.i('SyncService: flushing ${pending.length} pending writes.');

    for (final write in pending) {
      try {
        switch (write.method.toUpperCase()) {
          case 'POST':
            await _apiClient.post(write.path, data: write.body);
          case 'PUT':
            await _apiClient.put(write.path, data: write.body);
          case 'PATCH':
            await _apiClient.patch(write.path, data: write.body);
          case 'DELETE':
            await _apiClient.delete(write.path);
        }
        await _queue.remove(write.id);
        _logger.d('SyncService: replayed ${write.method} ${write.path}');
      } catch (e) {
        _logger.w(
            'SyncService: failed to replay ${write.method} ${write.path}: $e');
        // Stop on first failure — preserve order for subsequent retries.
        break;
      }
    }
  }
}

/// Riverpod provider for [SyncService].
final syncServiceProvider = Provider<SyncService>((ref) {
  return SyncService(
    apiClient: ref.watch(apiClientProvider),
    queue: PendingWritesQueue(),
    networkInfo: ref.watch(networkInfoProvider),
  );
});

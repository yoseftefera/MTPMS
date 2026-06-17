import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Provides the current network connectivity status and a stream of changes.
class ConnectivityService {
  ConnectivityService() : _connectivity = Connectivity();

  final Connectivity _connectivity;

  /// Returns `true` if the device currently has network access.
  Future<bool> get isConnected async {
    final result = await _connectivity.checkConnectivity();
    return _isOnline(result);
  }

  /// Stream of connectivity changes. Emits `true` when online, `false` when offline.
  Stream<bool> get onConnectivityChanged {
    return _connectivity.onConnectivityChanged.map(_isOnline);
  }

  bool _isOnline(ConnectivityResult result) {
    return result == ConnectivityResult.mobile ||
        result == ConnectivityResult.wifi ||
        result == ConnectivityResult.ethernet;
  }
}

// ---------------------------------------------------------------------------
// Riverpod Providers
// ---------------------------------------------------------------------------

/// Provider for [ConnectivityService].
final connectivityServiceProvider = Provider<ConnectivityService>((ref) {
  return ConnectivityService();
});

/// StreamProvider that emits the current online/offline state.
final isOnlineProvider = StreamProvider<bool>((ref) async* {
  final service = ref.read(connectivityServiceProvider);
  yield await service.isConnected;
  yield* service.onConnectivityChanged;
});

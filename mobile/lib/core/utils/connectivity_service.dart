import 'dart:async';
import 'package:connectivity_plus/connectivity_plus.dart';

/// Wraps [Connectivity] to provide a simple online/offline stream.
class ConnectivityService {
  ConnectivityService({Connectivity? connectivity})
      : _connectivity = connectivity ?? Connectivity();

  final Connectivity _connectivity;

  /// Emits `true` when the device is online, `false` when offline.
  Stream<bool> get onConnectivityChanged =>
      _connectivity.onConnectivityChanged.map(_isOnline);

  /// Returns `true` if the device currently has network access.
  Future<bool> get isConnected async {
    final result = await _connectivity.checkConnectivity();
    return _isOnline(result);
  }

  bool _isOnline(ConnectivityResult result) =>
      result != ConnectivityResult.none;
}

import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'network_info.dart';

/// Provides the current online/offline status as a [StreamProvider].
///
/// Widgets can watch this to show/hide the offline banner.
final connectivityStreamProvider = StreamProvider<bool>((ref) {
  return ref.watch(networkInfoProvider).onConnectivityChanged;
});

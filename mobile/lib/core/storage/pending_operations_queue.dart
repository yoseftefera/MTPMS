import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hive/hive.dart';
import 'package:uuid/uuid.dart';

import '../constants/app_constants.dart';

/// Represents a write operation that was queued while the device was offline.
class PendingOperation {
  final String id;
  final String method; // POST | PUT | PATCH | DELETE
  final String path;
  final Map<String, dynamic>? body;
  final DateTime queuedAt;

  PendingOperation({
    required this.id,
    required this.method,
    required this.path,
    this.body,
    required this.queuedAt,
  });

  factory PendingOperation.fromJson(Map<String, dynamic> json) {
    return PendingOperation(
      id: json['id'] as String,
      method: json['method'] as String,
      path: json['path'] as String,
      body: json['body'] != null
          ? Map<String, dynamic>.from(json['body'] as Map)
          : null,
      queuedAt: DateTime.parse(json['queued_at'] as String),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'method': method,
      'path': path,
      'body': body,
      'queued_at': queuedAt.toIso8601String(),
    };
  }
}

/// Manages a persistent queue of write operations for offline sync.
///
/// Operations are stored in the [AppConstants.pendingOpsBoxName] Hive box and
/// replayed in FIFO order when connectivity is restored.
class PendingOperationsQueue {
  static const _uuid = Uuid();

  Box<Map> get _box => Hive.box<Map>(AppConstants.pendingOpsBoxName);

  /// Enqueues a write operation to be synced when online.
  Future<void> enqueue({
    required String method,
    required String path,
    Map<String, dynamic>? body,
  }) async {
    final op = PendingOperation(
      id: _uuid.v4(),
      method: method,
      path: path,
      body: body,
      queuedAt: DateTime.now().toUtc(),
    );
    await _box.put(op.id, op.toJson());
  }

  /// Returns all pending operations in insertion order.
  List<PendingOperation> getAll() {
    return _box.values
        .map((v) => PendingOperation.fromJson(Map<String, dynamic>.from(v)))
        .toList()
      ..sort((a, b) => a.queuedAt.compareTo(b.queuedAt));
  }

  /// Removes a successfully synced operation by [id].
  Future<void> remove(String id) async {
    await _box.delete(id);
  }

  /// Returns the number of pending operations.
  int get count => _box.length;

  bool get isEmpty => _box.isEmpty;
}

// ---------------------------------------------------------------------------
// Riverpod provider
// ---------------------------------------------------------------------------

final pendingOperationsQueueProvider = Provider<PendingOperationsQueue>((ref) {
  return PendingOperationsQueue();
});

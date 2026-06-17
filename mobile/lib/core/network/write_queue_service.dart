import 'dart:convert';

import 'package:hive/hive.dart';

import '../constants/app_constants.dart';
import '../errors/exceptions.dart';

/// Represents a write operation that was queued while the device was offline.
class QueuedWriteOperation {
  const QueuedWriteOperation({
    required this.id,
    required this.method,
    required this.path,
    this.data,
    required this.queuedAt,
  });

  final String id;

  /// HTTP method: 'POST', 'PATCH', 'DELETE'
  final String method;

  /// API path, e.g. '/tenders/abc/bids'
  final String path;

  /// Request body (nullable for DELETE)
  final Map<String, dynamic>? data;

  final DateTime queuedAt;

  Map<String, dynamic> toJson() => {
        'id': id,
        'method': method,
        'path': path,
        'data': data,
        'queued_at': queuedAt.toIso8601String(),
      };

  factory QueuedWriteOperation.fromJson(Map<String, dynamic> json) {
    return QueuedWriteOperation(
      id: json['id'] as String,
      method: json['method'] as String,
      path: json['path'] as String,
      data: json['data'] as Map<String, dynamic>?,
      queuedAt: DateTime.parse(json['queued_at'] as String),
    );
  }
}

/// Persists write operations to Hive while offline and replays them when
/// connectivity is restored.
class WriteQueueService {
  WriteQueueService() {
    _box = Hive.box<String>(AppConstants.hiveWriteQueueBox);
  }

  late final Box<String> _box;

  /// Adds a write operation to the queue.
  Future<void> enqueue(QueuedWriteOperation operation) async {
    try {
      await _box.put(operation.id, jsonEncode(operation.toJson()));
    } catch (e) {
      throw CacheException(message: 'Failed to enqueue write operation: $e');
    }
  }

  /// Returns all queued operations in insertion order.
  List<QueuedWriteOperation> getAll() {
    try {
      return _box.values
          .map((raw) => QueuedWriteOperation.fromJson(
                jsonDecode(raw) as Map<String, dynamic>,
              ))
          .toList();
    } catch (e) {
      throw CacheException(message: 'Failed to read write queue: $e');
    }
  }

  /// Removes a successfully synced operation from the queue.
  Future<void> remove(String operationId) async {
    try {
      await _box.delete(operationId);
    } catch (e) {
      throw CacheException(message: 'Failed to remove queued operation: $e');
    }
  }

  /// Returns the number of pending operations.
  int get pendingCount => _box.length;

  /// Clears all queued operations (use with caution).
  Future<void> clear() async {
    await _box.clear();
  }
}

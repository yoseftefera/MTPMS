import 'package:hive/hive.dart';
import 'package:uuid/uuid.dart';

import '../constants/app_constants.dart';

/// Represents a write operation that was queued while offline.
class PendingWrite {
  final String id;
  final String method; // POST, PUT, PATCH, DELETE
  final String path;
  final Map<String, dynamic>? body;
  final DateTime queuedAt;

  const PendingWrite({
    required this.id,
    required this.method,
    required this.path,
    this.body,
    required this.queuedAt,
  });

  Map<String, dynamic> toJson() => {
        'id': id,
        'method': method,
        'path': path,
        'body': body,
        'queued_at': queuedAt.toIso8601String(),
      };

  factory PendingWrite.fromJson(Map<String, dynamic> json) => PendingWrite(
        id: json['id'] as String,
        method: json['method'] as String,
        path: json['path'] as String,
        body: json['body'] != null
            ? Map<String, dynamic>.from(json['body'] as Map)
            : null,
        queuedAt: DateTime.parse(json['queued_at'] as String),
      );
}

/// Manages a queue of write operations that failed due to being offline.
///
/// When connectivity is restored, the app should call [flush] to replay
/// all queued operations against the API.
class PendingWritesQueue {
  final Box<Map> _box;
  final _uuid = const Uuid();

  PendingWritesQueue() : _box = Hive.box<Map>(AppConstants.pendingWritesBox);

  /// Adds a write operation to the queue.
  Future<void> enqueue({
    required String method,
    required String path,
    Map<String, dynamic>? body,
  }) async {
    final write = PendingWrite(
      id: _uuid.v4(),
      method: method,
      path: path,
      body: body,
      queuedAt: DateTime.now(),
    );
    await _box.put(write.id, write.toJson());
  }

  /// Returns all pending write operations in queue order.
  List<PendingWrite> getAll() {
    return _box.values
        .map((raw) => PendingWrite.fromJson(Map<String, dynamic>.from(raw)))
        .toList()
      ..sort((a, b) => a.queuedAt.compareTo(b.queuedAt));
  }

  /// Removes a successfully replayed write from the queue.
  Future<void> remove(String id) => _box.delete(id);

  /// Clears all pending writes (e.g., after a full sync).
  Future<void> clear() => _box.clear();

  bool get isEmpty => _box.isEmpty;
  int get length => _box.length;
}

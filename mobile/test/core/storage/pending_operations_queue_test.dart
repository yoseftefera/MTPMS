import 'package:flutter_test/flutter_test.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:pmp_mobile/core/constants/app_constants.dart';
import 'package:pmp_mobile/core/storage/pending_operations_queue.dart';

void main() {
  group('PendingOperationsQueue', () {
    setUp(() async {
      Hive.init('test_hive_queue');
      await Hive.openBox<Map>(AppConstants.pendingOpsBoxName);
    });

    tearDown(() async {
      await Hive.box<Map>(AppConstants.pendingOpsBoxName).clear();
      await Hive.deleteBoxFromDisk(AppConstants.pendingOpsBoxName);
    });

    test('enqueue persists an operation and getAll returns it', () async {
      final queue = PendingOperationsQueue();

      await queue.enqueue(
        method: 'POST',
        path: '/api/v1/tenders/123/bids',
        body: {'amount': 5000},
      );

      final ops = queue.getAll();
      expect(ops, hasLength(1));
      expect(ops.first.method, equals('POST'));
      expect(ops.first.path, equals('/api/v1/tenders/123/bids'));
      expect(ops.first.body, equals({'amount': 5000}));
    });

    test('getAll returns multiple operations in chronological order', () async {
      final queue = PendingOperationsQueue();

      // Enqueue a few operations in sequence.
      await queue.enqueue(method: 'POST', path: '/api/v1/bids', body: {'a': 1});
      await Future<void>.delayed(const Duration(milliseconds: 5));
      await queue
          .enqueue(method: 'PATCH', path: '/api/v1/bids/1', body: {'b': 2});
      await Future<void>.delayed(const Duration(milliseconds: 5));
      await queue.enqueue(method: 'DELETE', path: '/api/v1/bids/2');

      final ops = queue.getAll();
      expect(ops, hasLength(3));
      // Verify FIFO order.
      expect(ops[0].method, equals('POST'));
      expect(ops[1].method, equals('PATCH'));
      expect(ops[2].method, equals('DELETE'));
    });

    test('remove deletes a specific operation by id', () async {
      final queue = PendingOperationsQueue();

      await queue.enqueue(method: 'POST', path: '/api/v1/invoices');
      final before = queue.getAll();
      expect(before, hasLength(1));

      await queue.remove(before.first.id);

      final after = queue.getAll();
      expect(after, isEmpty);
    });

    test('count reflects the number of queued operations', () async {
      final queue = PendingOperationsQueue();
      expect(queue.count, equals(0));
      expect(queue.isEmpty, isTrue);

      await queue.enqueue(method: 'POST', path: '/api/v1/bids');
      expect(queue.count, equals(1));
      expect(queue.isEmpty, isFalse);

      await queue.enqueue(method: 'POST', path: '/api/v1/bids');
      expect(queue.count, equals(2));
    });

    test('enqueue without body stores null body', () async {
      final queue = PendingOperationsQueue();

      await queue.enqueue(method: 'DELETE', path: '/api/v1/bids/99');

      final ops = queue.getAll();
      expect(ops.first.body, isNull);
    });

    test('PendingOperation serialises and deserialises correctly', () {
      final original = PendingOperation(
        id: 'abc-123',
        method: 'PUT',
        path: '/api/v1/purchase-orders/5/accept',
        body: {'note': 'approved'},
        queuedAt: DateTime.utc(2024, 1, 15, 10, 30),
      );

      final json = original.toJson();
      final restored = PendingOperation.fromJson(json);

      expect(restored.id, equals(original.id));
      expect(restored.method, equals(original.method));
      expect(restored.path, equals(original.path));
      expect(restored.body, equals(original.body));
      expect(restored.queuedAt, equals(original.queuedAt));
    });
  });
}

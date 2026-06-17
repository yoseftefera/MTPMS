import 'package:flutter_test/flutter_test.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:pmp_mobile/core/constants/app_constants.dart';
import 'package:pmp_mobile/core/storage/hive_service.dart';

void main() {
  group('HiveService', () {
    setUp(() async {
      // Use an in-memory Hive for tests.
      Hive.init('test_hive');
      await Hive.openBox<Map>(AppConstants.tendersBoxName);
      await Hive.openBox<Map>(AppConstants.dashboardBoxName);
    });

    tearDown(() async {
      await Hive.deleteBoxFromDisk(AppConstants.tendersBoxName);
      await Hive.deleteBoxFromDisk(AppConstants.dashboardBoxName);
    });

    test('put and get returns data within TTL', () async {
      const key = 'test_key';
      final data = {'foo': 'bar', 'count': 42};

      await HiveService.put(AppConstants.tendersBoxName, key, data);

      final result = HiveService.get(
        AppConstants.tendersBoxName,
        key,
        ttl: const Duration(hours: 1),
      );

      expect(result, isNotNull);
      expect(result!['foo'], equals('bar'));
      expect(result['count'], equals(42));
    });

    test('get returns null when entry does not exist', () {
      final result = HiveService.get(
        AppConstants.tendersBoxName,
        'nonexistent',
        ttl: const Duration(hours: 1),
      );
      expect(result, isNull);
    });

    test('get returns null when TTL has expired', () async {
      const key = 'expired_key';
      final data = {'value': 'test'};

      // Write an entry with a manually backdated _cachedAt timestamp.
      final box = Hive.box<Map>(AppConstants.tendersBoxName);
      final backdated = DateTime.now().toUtc().subtract(const Duration(hours: 2));
      await box.put(key, {
        ...data,
        '_cachedAt': backdated.toIso8601String(),
      });

      // 1-hour TTL should consider a 2-hour-old entry expired.
      final result = HiveService.get(
        AppConstants.tendersBoxName,
        key,
        ttl: const Duration(hours: 1),
      );

      expect(result, isNull);
    });

    test('delete removes the entry', () async {
      const key = 'delete_key';
      await HiveService.put(
        AppConstants.tendersBoxName,
        key,
        {'x': 1},
      );

      await HiveService.delete(AppConstants.tendersBoxName, key);

      final result = HiveService.get(
        AppConstants.tendersBoxName,
        key,
        ttl: const Duration(hours: 1),
      );
      expect(result, isNull);
    });

    test('clearBox removes all entries', () async {
      await HiveService.put(AppConstants.tendersBoxName, 'k1', {'a': 1});
      await HiveService.put(AppConstants.tendersBoxName, 'k2', {'b': 2});

      await HiveService.clearBox(AppConstants.tendersBoxName);

      final box = Hive.box<Map>(AppConstants.tendersBoxName);
      expect(box.isEmpty, isTrue);
    });

    test('putList uses 24-hour TTL and getList retrieves it', () async {
      const key = 'list_key';
      final data = {'items': [1, 2, 3]};

      await HiveService.putList(AppConstants.tendersBoxName, key, data);
      final result = HiveService.getList(AppConstants.tendersBoxName, key);

      expect(result, isNotNull);
      expect(result!['items'], equals([1, 2, 3]));
    });

    test('putDetail uses 1-hour TTL and getDetail retrieves it', () async {
      const key = 'detail_key';
      final data = {'id': 'abc', 'title': 'Test Tender'};

      await HiveService.putDetail(AppConstants.tendersBoxName, key, data);
      final result = HiveService.getDetail(AppConstants.tendersBoxName, key);

      expect(result, isNotNull);
      expect(result!['id'], equals('abc'));
    });

    test('_cachedAt is stamped on put', () async {
      const key = 'stamp_key';
      await HiveService.put(AppConstants.tendersBoxName, key, {'v': 1});

      final box = Hive.box<Map>(AppConstants.tendersBoxName);
      final raw = Map<String, dynamic>.from(box.get(key)!);
      expect(raw['_cachedAt'], isNotNull);
      expect(DateTime.tryParse(raw['_cachedAt'] as String), isNotNull);
    });
  });
}

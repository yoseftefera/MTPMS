import 'package:flutter_test/flutter_test.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:pmp_mobile/core/cache/cache_entry.dart';
import 'package:pmp_mobile/core/cache/hive_cache_service.dart';
import 'package:pmp_mobile/core/constants/app_constants.dart';
import 'package:pmp_mobile/core/errors/exceptions.dart';

void main() {
  late HiveCacheService cache;

  setUpAll(() async {
    // Use an in-memory Hive for tests
    Hive.init('test_hive');
    if (!Hive.isAdapterRegistered(0)) {
      Hive.registerAdapter(CacheEntryAdapter());
    }
    await Hive.openBox<CacheEntry>(AppConstants.tenderBoxName);
    cache = HiveCacheService.instance;
  });

  tearDown(() async {
    await Hive.box<CacheEntry>(AppConstants.tenderBoxName).clear();
  });

  tearDownAll(() async {
    await Hive.close();
  });

  group('HiveCacheService', () {
    test('write and readList returns stored value within TTL', () async {
      const key = 'test_list';
      final value = [
        {'id': '1', 'title': 'Tender A'}
      ];

      await cache.write(
        boxName: AppConstants.tenderBoxName,
        key: key,
        value: value,
      );

      final result = cache.readList(
        boxName: AppConstants.tenderBoxName,
        key: key,
      );

      expect(result, isA<List>());
      expect((result as List).first['title'], equals('Tender A'));
    });

    test('readList throws CacheException for missing key', () {
      expect(
        () => cache.readList(
          boxName: AppConstants.tenderBoxName,
          key: 'nonexistent',
        ),
        throwsA(isA<CacheException>()),
      );
    });

    test('readDetail throws CacheException for expired entry', () async {
      const key = 'expired_detail';
      // Write an entry with a past timestamp by manipulating the box directly
      final box = Hive.box<CacheEntry>(AppConstants.tenderBoxName);
      await box.put(
        key,
        CacheEntry(
          data: '{"id":"1"}',
          cachedAt: DateTime.now().toUtc().subtract(const Duration(hours: 2)),
        ),
      );

      expect(
        () => cache.readDetail(
          boxName: AppConstants.tenderBoxName,
          key: key,
        ),
        throwsA(isA<CacheException>()),
      );
    });

    test('delete removes the key', () async {
      const key = 'to_delete';
      await cache.write(
        boxName: AppConstants.tenderBoxName,
        key: key,
        value: {'id': '1'},
      );

      await cache.delete(boxName: AppConstants.tenderBoxName, key: key);

      expect(
        () => cache.readList(boxName: AppConstants.tenderBoxName, key: key),
        throwsA(isA<CacheException>()),
      );
    });

    test('clearBox removes all entries', () async {
      await cache.write(
        boxName: AppConstants.tenderBoxName,
        key: 'a',
        value: 1,
      );
      await cache.write(
        boxName: AppConstants.tenderBoxName,
        key: 'b',
        value: 2,
      );

      await cache.clearBox(AppConstants.tenderBoxName);

      expect(
        Hive.box<CacheEntry>(AppConstants.tenderBoxName).isEmpty,
        isTrue,
      );
    });
  });
}

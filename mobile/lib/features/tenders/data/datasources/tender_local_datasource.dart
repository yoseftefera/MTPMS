import 'package:hive/hive.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/storage/local_cache.dart';
import '../models/tender_model.dart';

/// Local Hive-backed data source for tender caching.
///
/// List data uses a 24-hour TTL; detail data uses a 1-hour TTL.
abstract class TenderLocalDataSource {
  Future<void> cacheTenderList(List<TenderModel> tenders);
  List<TenderModel>? getCachedTenderList();

  Future<void> cacheTenderDetail(TenderModel tender);
  TenderModel? getCachedTenderDetail(String id);
}

class TenderLocalDataSourceImpl implements TenderLocalDataSource {
  final LocalCache _listCache;
  final LocalCache _detailCache;

  TenderLocalDataSourceImpl()
      : _listCache =
            LocalCache.listCache(Hive.box<Map>(AppConstants.hiveBoxTenders)),
        _detailCache = LocalCache.detailCache(
            Hive.box<Map>(AppConstants.hiveBoxTenderDetail));

  static const String _listKey = 'tender_list';

  @override
  Future<void> cacheTenderList(List<TenderModel> tenders) async {
    await _listCache.put(_listKey, {
      'items': tenders.map((t) => t.toJson()).toList(),
    });
  }

  @override
  List<TenderModel>? getCachedTenderList() {
    final cached = _listCache.get(_listKey);
    if (cached == null) return null;
    final items = cached['items'] as List?;
    if (items == null) return null;
    return items
        .map((e) => TenderModel.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
  }

  @override
  Future<void> cacheTenderDetail(TenderModel tender) async {
    await _detailCache.put(tender.id, tender.toJson());
  }

  @override
  TenderModel? getCachedTenderDetail(String id) {
    final cached = _detailCache.get(id);
    if (cached == null) return null;
    return TenderModel.fromJson(cached);
  }
}

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../../../core/network/network_info.dart';
import '../../data/datasources/tender_remote_datasource.dart';
import '../../data/repositories/tender_repository_impl.dart';
import '../../domain/entities/tender.dart';
import '../../domain/repositories/tender_repository.dart';

final tenderRemoteDataSourceProvider = Provider<TenderRemoteDataSource>((ref) {
  return TenderRemoteDataSourceImpl(dio: ref.watch(dioProvider));
});

final tenderRepositoryProvider = Provider<TenderRepository>((ref) {
  return TenderRepositoryImpl(
    remote: ref.watch(tenderRemoteDataSourceProvider),
    networkInfo: ref.watch(networkInfoProvider),
  );
});

/// Paginated tender list provider.
final tenderListProvider = FutureProvider.family<List<Tender>, int>(
  (ref, page) async {
    final repo = ref.watch(tenderRepositoryProvider);
    final result = await repo.getTenders(page: page, status: 'published');
    return result.fold(
      (failure) => throw Exception(failure.message),
      (tenders) => tenders,
    );
  },
);

/// Single tender detail provider.
final tenderDetailProvider = FutureProvider.family<Tender, String>(
  (ref, tenderId) async {
    final repo = ref.watch(tenderRepositoryProvider);
    final result = await repo.getTenderById(tenderId);
    return result.fold(
      (failure) => throw Exception(failure.message),
      (tender) => tender,
    );
  },
);

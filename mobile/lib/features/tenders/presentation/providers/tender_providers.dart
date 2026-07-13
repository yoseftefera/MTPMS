import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../data/datasources/tender_remote_datasource.dart';
import '../../data/repositories/tender_repository_impl.dart';
import '../../domain/entities/tender.dart';
import '../../domain/repositories/tender_repository.dart';
import '../../domain/usecases/get_open_tenders.dart';

final tenderRemoteDataSourceProvider = Provider<TenderRemoteDataSource>((ref) {
  return TenderRemoteDataSourceImpl(ref.watch(apiClientProvider));
});

final tenderRepositoryProvider = Provider<TenderRepository>((ref) {
  return TenderRepositoryImpl(ref.watch(tenderRemoteDataSourceProvider));
});

final getOpenTendersProvider = Provider<GetOpenTenders>((ref) {
  return GetOpenTenders(ref.watch(tenderRepositoryProvider));
});

final openTendersProvider = FutureProvider<List<Tender>>((ref) async {
  final usecase = ref.watch(getOpenTendersProvider);
  final result = await usecase();
  return result.fold(
    (failure) => throw Exception(failure.message),
    (tenders) => tenders,
  );
});

final tenderDetailProvider =
    FutureProvider.family<Tender, String>((ref, tenderId) async {
  final repo = ref.watch(tenderRepositoryProvider);
  final result = await repo.getTender(tenderId);
  return result.fold(
    (failure) => throw Exception(failure.message),
    (tender) => tender,
  );
});

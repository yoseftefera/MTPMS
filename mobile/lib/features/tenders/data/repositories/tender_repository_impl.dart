import 'package:dartz/dartz.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/network/network_info.dart';
import '../../../../core/storage/hive_service.dart';
import '../../domain/entities/tender.dart';
import '../../domain/repositories/tender_repository.dart';
import '../datasources/tender_remote_datasource.dart';
import '../models/tender_model.dart';

class TenderRepositoryImpl implements TenderRepository {
  final TenderRemoteDataSource _remote;
  final NetworkInfo _networkInfo;

  const TenderRepositoryImpl({
    required TenderRemoteDataSource remote,
    required NetworkInfo networkInfo,
  })  : _remote = remote,
        _networkInfo = networkInfo;

  @override
  Future<Either<Failure, List<Tender>>> getTenders({
    int page = 1,
    String? category,
    String? status,
  }) async {
    final cacheKey = 'tenders_p${page}_c${category ?? ''}_s${status ?? ''}';

    if (await _networkInfo.isConnected) {
      try {
        final tenders = await _remote.getTenders(
          page: page,
          category: category,
          status: status,
        );
        // Cache the result (24-hour TTL for list data).
        await HiveService.putList(
          AppConstants.tendersBoxName,
          cacheKey,
          {'items': tenders.map((t) => (t).toJson()).toList()},
        );
        return Right(tenders);
      } on NetworkException {
        return const Left(NetworkFailure());
      } on AuthException catch (e) {
        return Left(AuthFailure(message: e.message));
      } on AppException catch (e) {
        return Left(
            ServerFailure(message: e.message, statusCode: e.statusCode));
      }
    }

    // Offline: return cached data.
    final cached = HiveService.getList(AppConstants.tendersBoxName, cacheKey);
    if (cached != null) {
      final items = (cached['items'] as List)
          .map((e) => TenderModel.fromJson(e as Map<String, dynamic>))
          .toList();
      return Right(items);
    }

    return const Left(NetworkFailure());
  }

  @override
  Future<Either<Failure, Tender>> getTenderById(String id) async {
    final cacheKey = 'tender_$id';

    if (await _networkInfo.isConnected) {
      try {
        final tender = await _remote.getTenderById(id);
        await HiveService.putDetail(
          AppConstants.tendersBoxName,
          cacheKey,
          (tender).toJson(),
        );
        return Right(tender);
      } on NotFoundException {
        return const Left(NotFoundFailure());
      } on AuthException catch (e) {
        return Left(AuthFailure(message: e.message));
      } on AppException catch (e) {
        return Left(
            ServerFailure(message: e.message, statusCode: e.statusCode));
      }
    }

    // Offline: return cached detail.
    final cached = HiveService.getDetail(AppConstants.tendersBoxName, cacheKey);
    if (cached != null) {
      return Right(TenderModel.fromJson(cached));
    }

    return const Left(NetworkFailure());
  }
}

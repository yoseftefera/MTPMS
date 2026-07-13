import 'dart:convert';

import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/storage/hive_service.dart';
import '../../domain/entities/tender.dart';
import '../../domain/repositories/tender_repository.dart';
import '../datasources/tender_remote_datasource.dart';
import '../models/tender_model.dart';

class TenderRepositoryImpl implements TenderRepository {
  final TenderRemoteDataSource _remoteDataSource;

  const TenderRepositoryImpl(this._remoteDataSource);

  @override
  Future<Either<Failure, List<Tender>>> getOpenTenders({int page = 1}) async {
    try {
      final models = await _remoteDataSource.getOpenTenders(page: page);
      // Cache first page only.
      if (page == 1) {
        await HiveService.putList(
          AppConstants.tendersBoxName,
          'open_tenders',
          {
            'items': jsonEncode(
                models.map((m) => (m as TenderModel).toJson()).toList())
          },
        );
      }
      return Right(models);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        final cached =
            HiveService.getList(AppConstants.tendersBoxName, 'open_tenders');
        if (cached != null) {
          final items = jsonDecode(cached['items'] as String) as List<dynamic>;
          return Right(
            items
                .map((e) => TenderModel.fromJson(e as Map<String, dynamic>))
                .toList(),
          );
        }
        return Left(const NetworkFailure());
      }
      return Left(ServerFailure(
        message: _parseError(e),
        statusCode: e.response?.statusCode,
      ));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, Tender>> getTender(String tenderId) async {
    try {
      final model = await _remoteDataSource.getTender(tenderId);
      await HiveService.putDetail(
        AppConstants.tendersBoxName,
        'tender_$tenderId',
        model.toJson(),
      );
      return Right(model);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        final cached = HiveService.getDetail(
          AppConstants.tendersBoxName,
          'tender_$tenderId',
        );
        if (cached != null) return Right(TenderModel.fromJson(cached));
        return Left(const NetworkFailure());
      }
      return Left(ServerFailure(
        message: _parseError(e),
        statusCode: e.response?.statusCode,
      ));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  String _parseError(DioException e) {
    try {
      final data = e.response?.data as Map<String, dynamic>?;
      return data?['message'] as String? ?? 'Failed to load tenders.';
    } catch (_) {
      return 'Failed to load tenders.';
    }
  }
}

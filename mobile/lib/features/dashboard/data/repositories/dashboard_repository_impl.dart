import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/storage/hive_service.dart';
import '../../domain/entities/dashboard_summary.dart';
import '../../domain/repositories/dashboard_repository.dart';
import '../datasources/dashboard_remote_datasource.dart';
import '../models/dashboard_summary_model.dart';

class DashboardRepositoryImpl implements DashboardRepository {
  final DashboardRemoteDataSource _remoteDataSource;

  const DashboardRepositoryImpl(this._remoteDataSource);

  @override
  Future<Either<Failure, DashboardSummary>> getDashboardSummary() async {
    try {
      final model = await _remoteDataSource.getDashboardSummary();
      await HiveService.putList(
        AppConstants.dashboardBoxName,
        'summary',
        model.toJson(),
      );
      return Right(model);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        // Attempt cache fallback.
        final cached =
            HiveService.getList(AppConstants.dashboardBoxName, 'summary');
        if (cached != null) {
          return Right(DashboardSummaryModel.fromJson(cached));
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

  String _parseError(DioException e) {
    try {
      final data = e.response?.data as Map<String, dynamic>?;
      return data?['message'] as String? ?? 'Failed to load dashboard.';
    } catch (_) {
      return 'Failed to load dashboard.';
    }
  }
}

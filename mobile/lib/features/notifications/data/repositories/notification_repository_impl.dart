import 'dart:convert';

import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/storage/hive_service.dart';
import '../../domain/entities/app_notification.dart';
import '../../domain/repositories/notification_repository.dart';
import '../datasources/notification_remote_datasource.dart';
import '../models/notification_model.dart';

class NotificationRepositoryImpl implements NotificationRepository {
  final NotificationRemoteDataSource _remoteDataSource;

  const NotificationRepositoryImpl(this._remoteDataSource);

  @override
  Future<Either<Failure, List<AppNotification>>> getNotifications(
      {int page = 1}) async {
    try {
      final models = await _remoteDataSource.getNotifications(page: page);
      if (page == 1) {
        await HiveService.putList(
          AppConstants.notificationsBoxName,
          'notifications',
          {
            'items': jsonEncode(
                models.map((m) => (m as NotificationModel).toJson()).toList())
          },
        );
      }
      return Right(models);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        final cached = HiveService.getList(
            AppConstants.notificationsBoxName, 'notifications');
        if (cached != null) {
          final items = jsonDecode(cached['items'] as String) as List<dynamic>;
          return Right(items
              .map((e) => NotificationModel.fromJson(e as Map<String, dynamic>))
              .toList());
        }
        return Left(const NetworkFailure());
      }
      return Left(ServerFailure(message: _parseError(e)));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, void>> markAsRead(String notificationId) async {
    try {
      await _remoteDataSource.markAsRead(notificationId);
      return const Right(null);
    } on DioException catch (e) {
      return Left(ServerFailure(message: _parseError(e)));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, void>> markAllAsRead() async {
    try {
      await _remoteDataSource.markAllAsRead();
      return const Right(null);
    } on DioException catch (e) {
      return Left(ServerFailure(message: _parseError(e)));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, int>> getUnreadCount() async {
    try {
      final count = await _remoteDataSource.getUnreadCount();
      return Right(count);
    } on DioException catch (e) {
      return Left(ServerFailure(message: _parseError(e)));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  String _parseError(DioException e) {
    try {
      final data = e.response?.data as Map<String, dynamic>?;
      return data?['message'] as String? ?? 'Notification error.';
    } catch (_) {
      return 'Notification error.';
    }
  }
}

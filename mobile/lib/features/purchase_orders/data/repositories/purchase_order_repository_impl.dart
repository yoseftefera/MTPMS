import 'dart:convert';

import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/network/network_info.dart';
import '../../../../core/storage/hive_service.dart';
import '../../../../core/storage/pending_operations_queue.dart';
import '../../domain/entities/purchase_order.dart';
import '../../domain/repositories/purchase_order_repository.dart';
import '../datasources/purchase_order_remote_datasource.dart';
import '../models/purchase_order_model.dart';

class PurchaseOrderRepositoryImpl implements PurchaseOrderRepository {
  final PurchaseOrderRemoteDataSource _remoteDataSource;
  final NetworkInfo _networkInfo;
  final PendingOperationsQueue _queue;

  const PurchaseOrderRepositoryImpl(
    this._remoteDataSource,
    this._networkInfo,
    this._queue,
  );

  @override
  Future<Either<Failure, List<PurchaseOrder>>> getPurchaseOrders(
      {int page = 1}) async {
    try {
      final models = await _remoteDataSource.getPurchaseOrders(page: page);
      if (page == 1) {
        await HiveService.putList(
          AppConstants.purchaseOrdersBoxName,
          'purchase_orders',
          {
            'items': jsonEncode(
                models.map((m) => (m as PurchaseOrderModel).toJson()).toList())
          },
        );
      }
      return Right(models);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        final cached = HiveService.getList(
            AppConstants.purchaseOrdersBoxName, 'purchase_orders');
        if (cached != null) {
          final items = jsonDecode(cached['items'] as String) as List<dynamic>;
          return Right(items
              .map(
                  (e) => PurchaseOrderModel.fromJson(e as Map<String, dynamic>))
              .toList());
        }
        return const Left(NetworkFailure());
      }
      return Left(ServerFailure(message: _parseError(e)));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, PurchaseOrder>> getPurchaseOrder(String poId) async {
    try {
      final model = await _remoteDataSource.getPurchaseOrder(poId);
      await HiveService.putDetail(
          AppConstants.purchaseOrdersBoxName, 'po_$poId', model.toJson());
      return Right(model);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        final cached = HiveService.getDetail(
            AppConstants.purchaseOrdersBoxName, 'po_$poId');
        if (cached != null) return Right(PurchaseOrderModel.fromJson(cached));
        return const Left(NetworkFailure());
      }
      return Left(ServerFailure(message: _parseError(e)));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, PurchaseOrder>> acceptPurchaseOrder(
      String poId) async {
    final isConnected = await _networkInfo.isConnected;
    if (!isConnected) {
      await _queue.enqueue(
        method: 'POST',
        path: '/purchase-orders/$poId/accept',
      );
      return const Left(NetworkFailure(
        message:
            'You are offline. Your acceptance has been queued and will be submitted when connectivity is restored.',
      ));
    }

    try {
      final model = await _remoteDataSource.acceptPurchaseOrder(poId);
      return Right(model);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        await _queue.enqueue(
          method: 'POST',
          path: '/purchase-orders/$poId/accept',
        );
        return const Left(NetworkFailure(
          message:
              'You are offline. Your acceptance has been queued and will be submitted when connectivity is restored.',
        ));
      }
      return Left(ServerFailure(
          message: _parseError(e), statusCode: e.response?.statusCode));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, PurchaseOrder>> rejectPurchaseOrder(
    String poId, {
    required String reason,
  }) async {
    final isConnected = await _networkInfo.isConnected;
    if (!isConnected) {
      await _queue.enqueue(
        method: 'POST',
        path: '/purchase-orders/$poId/reject',
        body: {'reason': reason},
      );
      return const Left(NetworkFailure(
        message:
            'You are offline. Your rejection has been queued and will be submitted when connectivity is restored.',
      ));
    }

    try {
      final model =
          await _remoteDataSource.rejectPurchaseOrder(poId, reason: reason);
      return Right(model);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        await _queue.enqueue(
          method: 'POST',
          path: '/purchase-orders/$poId/reject',
          body: {'reason': reason},
        );
        return const Left(NetworkFailure(
          message:
              'You are offline. Your rejection has been queued and will be submitted when connectivity is restored.',
        ));
      }
      return Left(ServerFailure(
          message: _parseError(e), statusCode: e.response?.statusCode));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  String _parseError(DioException e) {
    try {
      final data = e.response?.data as Map<String, dynamic>?;
      return data?['message'] as String? ?? 'Operation failed.';
    } catch (_) {
      return 'Operation failed.';
    }
  }
}

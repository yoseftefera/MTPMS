import 'dart:convert';

import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/network/network_info.dart';
import '../../../../core/storage/hive_service.dart';
import '../../../../core/storage/pending_operations_queue.dart';
import '../../domain/entities/invoice.dart';
import '../../domain/repositories/invoice_repository.dart';
import '../datasources/invoice_remote_datasource.dart';
import '../models/invoice_model.dart';

class InvoiceRepositoryImpl implements InvoiceRepository {
  final InvoiceRemoteDataSource _remoteDataSource;
  final NetworkInfo _networkInfo;
  final PendingOperationsQueue _queue;

  const InvoiceRepositoryImpl(
    this._remoteDataSource,
    this._networkInfo,
    this._queue,
  );

  @override
  Future<Either<Failure, List<Invoice>>> getInvoices({int page = 1}) async {
    try {
      final models = await _remoteDataSource.getInvoices(page: page);
      if (page == 1) {
        await HiveService.putList(
          AppConstants.invoicesBoxName,
          'invoices',
          {'items': jsonEncode(models.map((m) => m.toJson()).toList())},
        );
      }
      return Right(models);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        final cached =
            HiveService.getList(AppConstants.invoicesBoxName, 'invoices');
        if (cached != null) {
          final items = jsonDecode(cached['items'] as String) as List<dynamic>;
          return Right(items
              .map((e) => InvoiceModel.fromJson(e as Map<String, dynamic>))
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
  Future<Either<Failure, Invoice>> submitInvoice({
    required String invoiceNumber,
    required double totalAmount,
    required String currency,
    String? purchaseOrderId,
    String? contractId,
    required List<Map<String, dynamic>> items,
    DateTime? invoiceDate,
    String? notes,
  }) async {
    final body = <String, dynamic>{
      'invoice_number': invoiceNumber,
      'total_amount': totalAmount.toString(),
      'currency': currency,
      if (purchaseOrderId != null) 'purchase_order_id': purchaseOrderId,
      if (contractId != null) 'contract_id': contractId,
      'items': items,
      if (invoiceDate != null) 'invoice_date': invoiceDate.toIso8601String(),
      if (notes != null) 'notes': notes,
    };

    // Queue if offline.
    final isConnected = await _networkInfo.isConnected;
    if (!isConnected) {
      await _queue.enqueue(
        method: 'POST',
        path: '/invoices',
        body: body,
      );
      return const Left(NetworkFailure(
        message:
            'You are offline. Your invoice has been queued and will be submitted when connectivity is restored.',
      ));
    }

    try {
      final model = await _remoteDataSource.submitInvoice(
        invoiceNumber: invoiceNumber,
        totalAmount: totalAmount,
        currency: currency,
        purchaseOrderId: purchaseOrderId,
        contractId: contractId,
        items: items,
        invoiceDate: invoiceDate,
        notes: notes,
      );
      return Right(model);
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        return Left(ValidationFailure(
          message: _parseError(e),
          errors: _parseValidationErrors(e),
        ));
      }
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        // Retry queue on connection failure.
        await _queue.enqueue(
          method: 'POST',
          path: '/invoices',
          body: body,
        );
        return const Left(NetworkFailure(
          message:
              'You are offline. Your invoice has been queued and will be submitted when connectivity is restored.',
        ));
      }
      return Left(ServerFailure(
          message: _parseError(e), statusCode: e.response?.statusCode));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, List<Payment>>> getPayments({int page = 1}) async {
    try {
      final models = await _remoteDataSource.getPayments(page: page);
      if (page == 1) {
        await HiveService.putList(
          AppConstants.invoicesBoxName,
          'payments',
          {'items': jsonEncode(models.map((m) => m.toJson()).toList())},
        );
      }
      return Right(models);
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        final cached =
            HiveService.getList(AppConstants.invoicesBoxName, 'payments');
        if (cached != null) {
          final items = jsonDecode(cached['items'] as String) as List<dynamic>;
          return Right(items
              .map((e) => PaymentModel.fromJson(e as Map<String, dynamic>))
              .toList());
        }
        return const Left(NetworkFailure());
      }
      return Left(ServerFailure(message: _parseError(e)));
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

  Map<String, List<String>> _parseValidationErrors(DioException e) {
    try {
      final data = e.response?.data as Map<String, dynamic>?;
      final errors = data?['errors'] as Map<String, dynamic>?;
      if (errors == null) return {};
      return errors.map(
        (k, v) => MapEntry(k, (v as List).map((e) => e.toString()).toList()),
      );
    } catch (_) {
      return {};
    }
  }
}

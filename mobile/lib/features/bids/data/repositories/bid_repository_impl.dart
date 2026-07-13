import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/errors/failures.dart';
import '../../../../core/network/network_info.dart';
import '../../../../core/storage/pending_operations_queue.dart';
import '../../domain/entities/bid.dart';
import '../../domain/repositories/bid_repository.dart';
import '../datasources/bid_remote_datasource.dart';

class BidRepositoryImpl implements BidRepository {
  final BidRemoteDataSource _remoteDataSource;
  final NetworkInfo _networkInfo;
  final PendingOperationsQueue _queue;

  const BidRepositoryImpl(
    this._remoteDataSource,
    this._networkInfo,
    this._queue,
  );

  @override
  Future<Either<Failure, Bid>> submitBid({
    required String tenderId,
    required double totalAmount,
    required int deliveryDays,
    String? technicalNotes,
    List<String>? documentPaths,
  }) async {
    // Check connectivity first — queue the write if offline.
    final isConnected = await _networkInfo.isConnected;
    if (!isConnected) {
      // File attachments cannot be queued for deferred upload; we only queue
      // text-based bids.  If documents are included, surface a network error
      // so the user knows to retry when online.
      if (documentPaths != null && documentPaths.isNotEmpty) {
        return const Left(NetworkFailure(
          message:
              'You are offline. Please reconnect to submit a bid with attachments.',
        ));
      }

      await _queue.enqueue(
        method: 'POST',
        path: '/tenders/$tenderId/bids',
        body: {
          'total_amount': totalAmount.toString(),
          'delivery_days': deliveryDays,
          if (technicalNotes != null) 'technical_notes': technicalNotes,
        },
      );

      return const Left(NetworkFailure(
        message:
            'You are offline. Your bid has been queued and will be submitted when connectivity is restored.',
      ));
    }

    try {
      final model = await _remoteDataSource.submitBid(
        tenderId: tenderId,
        totalAmount: totalAmount,
        deliveryDays: deliveryDays,
        technicalNotes: technicalNotes,
        documentPaths: documentPaths,
      );
      return Right(model);
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        return Left(ValidationFailure(
          message: _parseError(e, 'Validation error.'),
          errors: _parseValidationErrors(e),
        ));
      }
      if (e.response?.statusCode == 409) {
        return Left(ServerFailure(
          message: _parseError(
              e, 'You have already submitted a bid for this tender.'),
          statusCode: 409,
        ));
      }
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        // Connectivity check passed but request still failed (e.g. DNS issue).
        if (documentPaths == null || documentPaths.isEmpty) {
          await _queue.enqueue(
            method: 'POST',
            path: '/tenders/$tenderId/bids',
            body: {
              'total_amount': totalAmount.toString(),
              'delivery_days': deliveryDays,
              if (technicalNotes != null) 'technical_notes': technicalNotes,
            },
          );
          return const Left(NetworkFailure(
            message:
                'You are offline. Your bid has been queued and will be submitted when connectivity is restored.',
          ));
        }
        return const Left(NetworkFailure());
      }
      return Left(ServerFailure(
        message: _parseError(e, 'Failed to submit bid.'),
        statusCode: e.response?.statusCode,
      ));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  String _parseError(DioException e, String fallback) {
    try {
      final data = e.response?.data as Map<String, dynamic>?;
      return data?['message'] as String? ?? fallback;
    } catch (_) {
      return fallback;
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

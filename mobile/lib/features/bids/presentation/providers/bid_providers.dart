import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/errors/failures.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/network/network_info.dart';
import '../../../../core/storage/pending_operations_queue.dart';
import '../../data/datasources/bid_remote_datasource.dart';
import '../../data/repositories/bid_repository_impl.dart';
import '../../domain/entities/bid.dart';
import '../../domain/repositories/bid_repository.dart';
import '../../domain/usecases/submit_bid.dart';

final bidRemoteDataSourceProvider = Provider<BidRemoteDataSource>((ref) {
  return BidRemoteDataSourceImpl(ref.watch(apiClientProvider));
});

final bidRepositoryProvider = Provider<BidRepository>((ref) {
  return BidRepositoryImpl(
    ref.watch(bidRemoteDataSourceProvider),
    ref.watch(networkInfoProvider),
    ref.watch(pendingOperationsQueueProvider),
  );
});

final submitBidProvider = Provider<SubmitBid>((ref) {
  return SubmitBid(ref.watch(bidRepositoryProvider));
});

// ---------------------------------------------------------------------------
// Bid submission state
// ---------------------------------------------------------------------------

sealed class BidSubmissionState {
  const BidSubmissionState();
}

class BidSubmissionInitial extends BidSubmissionState {
  const BidSubmissionInitial();
}

class BidSubmissionLoading extends BidSubmissionState {
  const BidSubmissionLoading();
}

class BidSubmissionSuccess extends BidSubmissionState {
  final Bid bid;
  const BidSubmissionSuccess(this.bid);
}

class BidSubmissionError extends BidSubmissionState {
  final String message;
  final Map<String, List<String>> fieldErrors;
  const BidSubmissionError(this.message, {this.fieldErrors = const {}});
}

class BidSubmissionNotifier extends StateNotifier<BidSubmissionState> {
  final SubmitBid _submitBid;

  BidSubmissionNotifier(this._submitBid) : super(const BidSubmissionInitial());

  Future<void> submit({
    required String tenderId,
    required double totalAmount,
    required int deliveryDays,
    String? technicalNotes,
    List<String>? documentPaths,
  }) async {
    state = const BidSubmissionLoading();
    final result = await _submitBid(
      tenderId: tenderId,
      totalAmount: totalAmount,
      deliveryDays: deliveryDays,
      technicalNotes: technicalNotes,
      documentPaths: documentPaths,
    );
    result.fold(
      (failure) {
        if (failure is ValidationFailure) {
          state = BidSubmissionError(
            failure.message,
            fieldErrors: failure.errors,
          );
        } else {
          state = BidSubmissionError(failure.message);
        }
      },
      (bid) => state = BidSubmissionSuccess(bid),
    );
  }

  void reset() => state = const BidSubmissionInitial();
}

final bidSubmissionNotifierProvider = StateNotifierProvider.autoDispose<
    BidSubmissionNotifier, BidSubmissionState>((ref) {
  return BidSubmissionNotifier(ref.watch(submitBidProvider));
});

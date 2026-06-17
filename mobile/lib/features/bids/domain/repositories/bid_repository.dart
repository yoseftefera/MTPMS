import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/bid.dart';

abstract class BidRepository {
  /// Submits a new bid for the given tender.
  Future<Either<Failure, Bid>> submitBid({
    required String tenderId,
    required double totalAmount,
    required String currency,
    required int deliveryDays,
    String? technicalNotes,
  });

  /// Returns the supplier's existing bid for a tender, if any.
  Future<Either<Failure, Bid?>> getBidForTender(String tenderId);
}

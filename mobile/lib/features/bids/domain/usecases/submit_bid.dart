import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/bid.dart';
import '../repositories/bid_repository.dart';

class SubmitBid {
  final BidRepository _repository;

  const SubmitBid(this._repository);

  Future<Either<Failure, Bid>> call({
    required String tenderId,
    required double totalAmount,
    required int deliveryDays,
    String? technicalNotes,
    List<String>? documentPaths,
  }) =>
      _repository.submitBid(
        tenderId: tenderId,
        totalAmount: totalAmount,
        deliveryDays: deliveryDays,
        technicalNotes: technicalNotes,
        documentPaths: documentPaths,
      );
}

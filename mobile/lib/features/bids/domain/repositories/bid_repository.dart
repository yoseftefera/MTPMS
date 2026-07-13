import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/bid.dart';

abstract class BidRepository {
  Future<Either<Failure, Bid>> submitBid({
    required String tenderId,
    required double totalAmount,
    required int deliveryDays,
    String? technicalNotes,
    List<String>? documentPaths,
  });
}

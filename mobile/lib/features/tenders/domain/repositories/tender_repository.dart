import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/tender.dart';

abstract class TenderRepository {
  /// Returns published tenders the supplier can bid on.
  Future<Either<Failure, List<Tender>>> getOpenTenders({int page = 1});

  /// Returns a single tender by ID.
  Future<Either<Failure, Tender>> getTender(String tenderId);
}

import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/tender.dart';

abstract class TenderRepository {
  /// Returns a paginated list of published tenders.
  Future<Either<Failure, List<Tender>>> getTenders({
    int page = 1,
    String? category,
    String? status,
  });

  /// Returns the detail of a single tender by [id].
  Future<Either<Failure, Tender>> getTenderById(String id);
}

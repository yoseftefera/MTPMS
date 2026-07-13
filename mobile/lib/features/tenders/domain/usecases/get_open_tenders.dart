import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/tender.dart';
import '../repositories/tender_repository.dart';

class GetOpenTenders {
  final TenderRepository _repository;

  const GetOpenTenders(this._repository);

  Future<Either<Failure, List<Tender>>> call({int page = 1}) =>
      _repository.getOpenTenders(page: page);
}

import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/dashboard_summary.dart';
import '../repositories/dashboard_repository.dart';

class GetDashboardSummary {
  final DashboardRepository _repository;

  const GetDashboardSummary(this._repository);

  Future<Either<Failure, DashboardSummary>> call() =>
      _repository.getDashboardSummary();
}

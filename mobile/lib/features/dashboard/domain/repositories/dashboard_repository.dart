import 'package:dartz/dartz.dart';
import 'package:pmp_mobile/core/errors/failures.dart';
import 'package:pmp_mobile/features/dashboard/domain/entities/dashboard_summary.dart';

abstract class DashboardRepository {
  /// Fetches the supplier dashboard summary.
  ///
  /// Returns cached data (up to 24-hour TTL) when offline.
  Future<Either<Failure, DashboardSummary>> getSummary();
}

import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../repositories/auth_repository.dart';

/// Use case: sign out the current user and clear stored credentials.
class LogoutUsecase {
  final AuthRepository _repository;

  const LogoutUsecase(this._repository);

  Future<Either<Failure, void>> call() => _repository.logout();
}

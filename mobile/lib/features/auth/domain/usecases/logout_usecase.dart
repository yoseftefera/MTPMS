import 'package:dartz/dartz.dart';
import 'package:pmp_mobile/core/errors/failures.dart';
import 'package:pmp_mobile/features/auth/domain/repositories/auth_repository.dart';

/// Use-case: sign the current user out.
class LogoutUseCase {
  const LogoutUseCase(this._repository);

  final AuthRepository _repository;

  Future<Either<Failure, void>> call() => _repository.logout();
}

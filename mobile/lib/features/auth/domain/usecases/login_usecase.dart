import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/user.dart';
import '../repositories/auth_repository.dart';

/// Use case: authenticate a supplier with email, password and tenant ID.
class LoginUsecase {
  final AuthRepository _repository;

  const LoginUsecase(this._repository);

  Future<Either<Failure, User>> call({
    required String email,
    required String password,
    required String tenantId,
  }) =>
      _repository.login(email: email, password: password, tenantId: tenantId);
}

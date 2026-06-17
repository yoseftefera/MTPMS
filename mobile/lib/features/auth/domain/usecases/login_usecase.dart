import 'package:dartz/dartz.dart';
import 'package:equatable/equatable.dart';

import '../../../../core/errors/failures.dart';
import '../entities/user.dart';
import '../repositories/auth_repository.dart';

/// Use case: authenticate a supplier user with email, password, and tenant ID.
class LoginUseCase {
  final AuthRepository _repository;

  const LoginUseCase(this._repository);

  Future<Either<Failure, User>> call(LoginParams params) {
    return _repository.login(
      email: params.email,
      password: params.password,
      tenantId: params.tenantId,
    );
  }
}

class LoginParams extends Equatable {
  final String email;
  final String password;
  final String tenantId;

  const LoginParams({
    required this.email,
    required this.password,
    required this.tenantId,
  });

  @override
  List<Object?> get props => [email, tenantId];
}

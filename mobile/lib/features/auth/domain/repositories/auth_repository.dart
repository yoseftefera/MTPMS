import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/user.dart';

/// Contract for authentication operations.
abstract class AuthRepository {
  /// Authenticates with email/password and persists the JWT.
  Future<Either<Failure, User>> login({
    required String email,
    required String password,
    required String tenantId,
  });

  /// Invalidates the stored JWT and clears local credentials.
  Future<Either<Failure, void>> logout();

  /// Returns the currently authenticated user or null.
  Future<Either<Failure, User?>> getCurrentUser();

  /// Returns true when a valid access token is stored.
  Future<bool> isAuthenticated();
}

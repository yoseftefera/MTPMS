import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/user.dart';

/// Contract for authentication operations.
abstract class AuthRepository {
  /// Authenticates the user with [email] and [password].
  ///
  /// On success returns the authenticated [User].
  /// On failure returns a [Failure] (e.g., [AuthFailure], [NetworkFailure]).
  Future<Either<Failure, User>> login({
    required String email,
    required String password,
    required String tenantId,
  });

  /// Logs out the current user, invalidating the JWT on the server.
  Future<Either<Failure, void>> logout();

  /// Returns the currently cached authenticated user, or null if not logged in.
  Future<User?> getCachedUser();

  /// Requests a password reset email for [email].
  Future<Either<Failure, void>> requestPasswordReset({required String email});
}

import 'package:equatable/equatable.dart';

/// Base class for all domain-layer failures (used with dartz Either).
abstract class Failure extends Equatable {
  final String message;

  const Failure({required this.message});

  @override
  List<Object?> get props => [message];
}

/// Failure originating from a server/API error.
class ServerFailure extends Failure {
  final int? statusCode;

  const ServerFailure({required super.message, this.statusCode});

  @override
  List<Object?> get props => [message, statusCode];
}

/// Failure due to no network connectivity.
class NetworkFailure extends Failure {
  const NetworkFailure({super.message = 'No internet connection.'});
}

/// Failure due to authentication issues (expired/invalid token).
class AuthFailure extends Failure {
  const AuthFailure({required super.message});
}

/// Failure due to insufficient permissions.
class ForbiddenFailure extends Failure {
  const ForbiddenFailure({super.message = 'Access denied.'});
}

/// Failure when a resource is not found.
class NotFoundFailure extends Failure {
  const NotFoundFailure({super.message = 'Resource not found.'});
}

/// Failure due to server-side validation errors.
class ValidationFailure extends Failure {
  final Map<String, List<String>> errors;

  const ValidationFailure({required super.message, required this.errors});

  @override
  List<Object?> get props => [message, errors];
}

/// Failure when reading from or writing to the local cache.
class CacheFailure extends Failure {
  const CacheFailure({required super.message});
}

/// Failure when the tenant context cannot be resolved.
class TenantFailure extends Failure {
  const TenantFailure({super.message = 'Tenant could not be resolved.'});
}

import 'package:equatable/equatable.dart';

/// Base class for all domain-layer failures.
///
/// Repositories catch [AppException]s and convert them to [Failure] objects
/// so that use cases and the presentation layer remain decoupled from
/// data-layer implementation details.
abstract class Failure extends Equatable {
  const Failure(this.message);

  final String message;

  @override
  List<Object?> get props => [message];
}

// ---------------------------------------------------------------------------
// Network / Server Failures
// ---------------------------------------------------------------------------

class NetworkFailure extends Failure {
  const NetworkFailure([String message = 'No internet connection.'])
      : super(message);

  final int? statusCode = null;
}

class ServerFailure extends Failure {
  const ServerFailure({
    required String message,
    this.statusCode,
    this.errors,
  }) : super(message);

  final int? statusCode;
  final Map<String, List<String>>? errors;

  @override
  List<Object?> get props => [message, statusCode, errors];
}

class TimeoutFailure extends Failure {
  const TimeoutFailure([String message = 'Request timed out.'])
      : super(message);
}

// ---------------------------------------------------------------------------
// Authentication Failures
// ---------------------------------------------------------------------------

class UnauthorizedFailure extends Failure {
  const UnauthorizedFailure([String message = 'Unauthorized.'])
      : super(message);
}

class ForbiddenFailure extends Failure {
  const ForbiddenFailure([String message = 'Access denied.'])
      : super(message);
}

class NotFoundFailure extends Failure {
  const NotFoundFailure([String message = 'The requested resource was not found.'])
      : super(message);
}

// ---------------------------------------------------------------------------
// Cache Failures
// ---------------------------------------------------------------------------

class CacheFailure extends Failure {
  const CacheFailure([String message = 'Cache error.']) : super(message);
}

// ---------------------------------------------------------------------------
// Validation Failures
// ---------------------------------------------------------------------------

class ValidationFailure extends Failure {
  const ValidationFailure(String message, {this.fieldErrors}) : super(message);

  final Map<String, String>? fieldErrors;

  @override
  List<Object?> get props => [message, fieldErrors];
}

// ---------------------------------------------------------------------------
// Other Failures
// ---------------------------------------------------------------------------

class RateLimitFailure extends Failure {
  const RateLimitFailure([String message = 'Too many requests. Please try again later.'])
      : super(message);
}

class NoConnectivityFailure extends Failure {
  const NoConnectivityFailure([String message = 'No internet connection. Please check your network.'])
      : super(message);
}

class TenantResolutionFailure extends Failure {
  const TenantResolutionFailure([String message = 'Unable to resolve tenant context. Please log in again.'])
      : super(message);
}

class UnknownFailure extends Failure {
  const UnknownFailure([String message = 'An unexpected error occurred.'])
      : super(message);
}

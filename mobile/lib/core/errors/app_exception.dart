/// Base class for all application exceptions thrown in the data layer.
///
/// These are implementation-specific exceptions that are caught and converted
/// to [Failure] objects at the repository boundary.
abstract class AppException implements Exception {
  const AppException({required this.message, this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => '$runtimeType(message: $message, statusCode: $statusCode)';
}

/// Thrown when a network/HTTP request fails.
class NetworkException extends AppException {
  const NetworkException({
    required super.message,
    super.statusCode,
    this.requestPath,
  });

  final String? requestPath;
}

/// Thrown when the server returns a 401 Unauthorized response.
class UnauthorizedException extends AppException {
  const UnauthorizedException({
    super.message = 'Unauthorized. Please log in again.',
    super.statusCode = 401,
  });
}

/// Thrown when the server returns a 403 Forbidden response.
class ForbiddenException extends AppException {
  const ForbiddenException({
    super.message = 'You do not have permission to perform this action.',
    super.statusCode = 403,
  });
}

/// Thrown when the server returns a 404 Not Found response.
class NotFoundException extends AppException {
  const NotFoundException({
    super.message = 'The requested resource was not found.',
    super.statusCode = 404,
  });
}

/// Thrown when the server returns a 422 Unprocessable Entity (validation error).
class ValidationException extends AppException {
  const ValidationException({
    required super.message,
    super.statusCode = 422,
    this.errors,
  });

  /// Field-level validation errors from the API response envelope.
  final Map<String, List<String>>? errors;
}

/// Thrown when the server returns a 429 Too Many Requests response.
class RateLimitException extends AppException {
  const RateLimitException({
    super.message = 'Too many requests. Please try again later.',
    super.statusCode = 429,
  });
}

/// Thrown when the server returns a 5xx error.
class ServerException extends AppException {
  const ServerException({
    super.message = 'An unexpected server error occurred.',
    super.statusCode = 500,
  });
}

/// Thrown when the device has no network connectivity.
class NoConnectivityException extends AppException {
  const NoConnectivityException({
    super.message = 'No internet connection. Please check your network.',
  });
}

/// Thrown when a local cache read/write operation fails.
class CacheException extends AppException {
  const CacheException({
    required super.message,
  });
}

/// Thrown when the tenant cannot be resolved from the stored context.
class TenantResolutionException extends AppException {
  const TenantResolutionException({
    super.message = 'Unable to resolve tenant context. Please log in again.',
  });
}

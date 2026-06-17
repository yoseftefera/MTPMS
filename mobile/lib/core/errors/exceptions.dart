/// Base class for all application exceptions.
abstract class AppException implements Exception {
  final String message;
  final int? statusCode;

  const AppException({required this.message, this.statusCode});

  @override
  String toString() =>
      'AppException(message: $message, statusCode: $statusCode)';
}

/// Thrown when a server/API request fails.
class ServerException extends AppException {
  const ServerException({required super.message, super.statusCode});
}

/// Thrown when the device has no network connectivity.
class NetworkException extends AppException {
  const NetworkException({super.message = 'No internet connection.'});
}

/// Thrown when authentication fails or the token is invalid/expired.
class AuthException extends AppException {
  const AuthException({required super.message, super.statusCode = 401});
}

/// Thrown when the user does not have permission to perform an action.
class ForbiddenException extends AppException {
  const ForbiddenException(
      {super.message = 'Access denied.', super.statusCode = 403});
}

/// Thrown when a requested resource is not found.
class NotFoundException extends AppException {
  const NotFoundException(
      {super.message = 'Resource not found.', super.statusCode = 404});
}

/// Thrown when the server returns validation errors (HTTP 422).
class ValidationException extends AppException {
  final Map<String, List<String>> errors;

  const ValidationException({
    required super.message,
    required this.errors,
    super.statusCode = 422,
  });
}

/// Thrown when reading from or writing to the local cache fails.
class CacheException extends AppException {
  const CacheException({super.message = 'Cache operation failed.'});
}

/// Thrown when a cache entry has exceeded its TTL.
class CacheExpiredException extends AppException {
  const CacheExpiredException({super.message = 'Cache entry has expired.'});
}

/// Thrown when a tenant cannot be resolved from the request context.
class TenantException extends AppException {
  const TenantException(
      {super.message = 'Tenant could not be resolved.',
      super.statusCode = 401});
}

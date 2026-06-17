import 'package:dio/dio.dart';
import 'package:pmp_mobile/core/errors/app_exception.dart';

/// Converts a [DioException] into a typed [AppException].
AppException handleDioException(DioException e) {
  switch (e.type) {
    case DioExceptionType.connectionTimeout:
    case DioExceptionType.sendTimeout:
    case DioExceptionType.receiveTimeout:
    case DioExceptionType.connectionError:
      return const NoConnectivityException();

    case DioExceptionType.badResponse:
      final statusCode = e.response?.statusCode ?? 0;
      final body = e.response?.data;
      final message = (body is Map<String, dynamic>)
          ? (body['message'] as String? ?? 'Server error.')
          : 'Server error.';
      final errors = (body is Map<String, dynamic>)
          ? body['errors'] as Map<String, dynamic>?
          : null;

      if (statusCode == 401) {
        return UnauthorizedException(message: message);
      }
      if (statusCode == 403) {
        return ForbiddenException(message: message);
      }
      if (statusCode == 404) {
        return NotFoundException(message: message);
      }
      if (statusCode == 429) {
        return const RateLimitException();
      }
      if (statusCode == 422 && errors != null) {
        final fieldErrors = errors.map(
          (k, v) => MapEntry(
            k,
            (v as List<dynamic>).map((e) => e.toString()).toList(),
          ),
        );
        return ValidationException(message: message, errors: fieldErrors);
      }
      return ServerException(
        message: message,
        statusCode: statusCode,
      );

    case DioExceptionType.cancel:
      return const ServerException(message: 'Request cancelled.');

    case DioExceptionType.unknown:
    default:
      return NoConnectivityException(
        message: e.message ?? 'Unknown network error.',
      );
  }
}

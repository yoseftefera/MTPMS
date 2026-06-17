import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../constants/app_constants.dart';
import '../errors/app_exception.dart';

/// Dio interceptor that attaches the JWT access token to every outgoing request
/// and handles 401 responses by attempting a token refresh.
///
/// On a 401 response the interceptor:
///   1. Reads the stored refresh token.
///   2. Calls the `/auth/refresh` endpoint.
///   3. Persists the new access token.
///   4. Retries the original request with the new token.
///   5. If refresh fails, clears stored credentials and throws [UnauthorizedException].
class AuthInterceptor extends Interceptor {
  AuthInterceptor({
    FlutterSecureStorage? secureStorage,
  }) : _secureStorage = secureStorage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _secureStorage;

  // Tracks whether a refresh is already in progress to prevent concurrent
  // refresh calls when multiple requests fail with 401 simultaneously.
  bool _isRefreshing = false;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final token = await _secureStorage.read(key: AppConstants.accessTokenKey);
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    if (err.response?.statusCode == 401 && !_isRefreshing) {
      _isRefreshing = true;
      try {
        final newToken = await _refreshAccessToken(err.requestOptions);
        if (newToken != null) {
          // Retry the original request with the refreshed token.
          final retryOptions = err.requestOptions
            ..headers['Authorization'] = 'Bearer $newToken';

          final dio = Dio();
          final response = await dio.fetch(retryOptions);
          handler.resolve(response);
          return;
        }
      } catch (_) {
        await _clearCredentials();
        handler.reject(
          DioException(
            requestOptions: err.requestOptions,
            error: const UnauthorizedException(),
            type: DioExceptionType.badResponse,
          ),
        );
        return;
      } finally {
        _isRefreshing = false;
      }
    }
    handler.next(err);
  }

  /// Attempts to refresh the access token using the stored refresh token.
  /// Returns the new access token on success, or null if no refresh token exists.
  Future<String?> _refreshAccessToken(RequestOptions originalOptions) async {
    final refreshToken =
        await _secureStorage.read(key: AppConstants.refreshTokenKey);
    if (refreshToken == null || refreshToken.isEmpty) {
      return null;
    }

    final dio = Dio(BaseOptions(baseUrl: AppConstants.apiBaseUrl));
    final response = await dio.post(
      '/auth/refresh',
      data: {'refresh_token': refreshToken},
    );

    final newAccessToken = response.data['data']['access_token'] as String?;
    if (newAccessToken != null) {
      await _secureStorage.write(
        key: AppConstants.accessTokenKey,
        value: newAccessToken,
      );
    }
    return newAccessToken;
  }

  Future<void> _clearCredentials() async {
    await _secureStorage.deleteAll();
  }
}

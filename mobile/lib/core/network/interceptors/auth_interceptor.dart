import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../constants/app_constants.dart';

/// Dio interceptor that attaches the JWT Bearer token to every outgoing request
/// and handles 401 responses by attempting a token refresh.
///
/// On a 401 response the interceptor:
///   1. Reads the stored refresh token.
///   2. Calls POST /auth/refresh to obtain a new access token.
///   3. Retries the original request with the new token.
///   4. If refresh fails, clears stored credentials and signals the app to
///      redirect to the login screen.
class AuthInterceptor extends Interceptor {
  final FlutterSecureStorage _secureStorage;

  // Separate Dio instance used only for the refresh call to avoid recursion.
  final Dio _refreshDio;

  bool _isRefreshing = false;
  final List<RequestOptions> _pendingRequests = [];

  AuthInterceptor(Ref ref)
      : _secureStorage = const FlutterSecureStorage(),
        _refreshDio = Dio(
          BaseOptions(
            baseUrl: AppConstants.apiBaseUrl,
            connectTimeout: AppConstants.connectTimeout,
            receiveTimeout: AppConstants.receiveTimeout,
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
            },
          ),
        );

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
    if (err.response?.statusCode != 401) {
      handler.next(err);
      return;
    }

    // Avoid recursive refresh loops (e.g., the refresh endpoint itself 401s).
    if (err.requestOptions.path.contains('/auth/refresh')) {
      await _clearCredentials();
      handler.next(err);
      return;
    }

    if (_isRefreshing) {
      // Queue the request until the refresh completes.
      _pendingRequests.add(err.requestOptions);
      return;
    }

    _isRefreshing = true;

    try {
      final refreshToken =
          await _secureStorage.read(key: AppConstants.refreshTokenKey);

      if (refreshToken == null) {
        await _clearCredentials();
        handler.next(err);
        return;
      }

      final response = await _refreshDio.post(
        '/auth/refresh',
        data: {'refresh_token': refreshToken},
      );

      final newAccessToken = response.data['data']['access_token'] as String;
      final newRefreshToken = response.data['data']['refresh_token'] as String?;

      await _secureStorage.write(
        key: AppConstants.accessTokenKey,
        value: newAccessToken,
      );
      if (newRefreshToken != null) {
        await _secureStorage.write(
          key: AppConstants.refreshTokenKey,
          value: newRefreshToken,
        );
      }

      // Retry the original request with the new token.
      final retryOptions = err.requestOptions;
      retryOptions.headers['Authorization'] = 'Bearer $newAccessToken';

      final retryResponse = await _refreshDio.fetch(retryOptions);
      handler.resolve(retryResponse);

      // Retry any queued requests.
      for (final pending in _pendingRequests) {
        pending.headers['Authorization'] = 'Bearer $newAccessToken';
        await _refreshDio.fetch(pending);
      }
      _pendingRequests.clear();
    } catch (_) {
      await _clearCredentials();
      _pendingRequests.clear();
      handler.next(err);
    } finally {
      _isRefreshing = false;
    }
  }

  Future<void> _clearCredentials() async {
    await _secureStorage.delete(key: AppConstants.accessTokenKey);
    await _secureStorage.delete(key: AppConstants.refreshTokenKey);
    await _secureStorage.delete(key: AppConstants.tenantIdKey);
    await _secureStorage.delete(key: AppConstants.userIdKey);
  }
}

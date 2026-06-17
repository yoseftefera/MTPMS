import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../constants/app_constants.dart';
import 'interceptors/auth_interceptor.dart';
import 'interceptors/tenant_interceptor.dart';

/// Dio-based HTTP client configured with auth and tenant interceptors.
///
/// All requests are sent to [AppConstants.apiBaseUrl] with JSON headers.
/// The [AuthInterceptor] attaches the Bearer token and handles 401 refresh.
/// The [TenantInterceptor] attaches the X-Tenant-ID header on every request.
class ApiClient {
  late final Dio _dio;

  ApiClient({
    required AuthInterceptor authInterceptor,
    required TenantInterceptor tenantInterceptor,
  }) {
    _dio = Dio(
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

    _dio.interceptors.addAll([
      authInterceptor,
      tenantInterceptor,
      LogInterceptor(
        requestBody: false,
        responseBody: false,
        logPrint: (obj) => _log(obj.toString()),
      ),
    ]);
  }

  Dio get dio => _dio;

  // ---------------------------------------------------------------------------
  // Convenience HTTP wrappers (delegate to Dio)
  // ---------------------------------------------------------------------------

  Future<dynamic> get(
    String path, {
    Map<String, dynamic>? queryParameters,
    Options? options,
  }) async {
    final response = await _dio.get<dynamic>(
      path,
      queryParameters: queryParameters,
      options: options,
    );
    return response.data;
  }

  Future<dynamic> post(
    String path, {
    dynamic data,
    Map<String, dynamic>? queryParameters,
    Options? options,
  }) async {
    final response = await _dio.post<dynamic>(
      path,
      data: data,
      queryParameters: queryParameters,
      options: options,
    );
    return response.data;
  }

  Future<dynamic> put(
    String path, {
    dynamic data,
    Map<String, dynamic>? queryParameters,
    Options? options,
  }) async {
    final response = await _dio.put<dynamic>(
      path,
      data: data,
      queryParameters: queryParameters,
      options: options,
    );
    return response.data;
  }

  Future<dynamic> patch(
    String path, {
    dynamic data,
    Map<String, dynamic>? queryParameters,
    Options? options,
  }) async {
    final response = await _dio.patch<dynamic>(
      path,
      data: data,
      queryParameters: queryParameters,
      options: options,
    );
    return response.data;
  }

  Future<dynamic> delete(
    String path, {
    dynamic data,
    Map<String, dynamic>? queryParameters,
    Options? options,
  }) async {
    final response = await _dio.delete<dynamic>(
      path,
      data: data,
      queryParameters: queryParameters,
      options: options,
    );
    return response.data;
  }

  void _log(String message) {
    // In production builds this is a no-op; replace with a proper logger.
    assert(() {
      // ignore: avoid_print
      print('[ApiClient] $message');
      return true;
    }());
  }
}

// ---------------------------------------------------------------------------
// Riverpod providers
// ---------------------------------------------------------------------------

/// Provider for [AuthInterceptor].
final authInterceptorProvider = Provider<AuthInterceptor>((ref) {
  return AuthInterceptor(ref);
});

/// Provider for [TenantInterceptor].
final tenantInterceptorProvider = Provider<TenantInterceptor>((ref) {
  return TenantInterceptor(ref);
});

/// Provider for the singleton [ApiClient].
final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(
    authInterceptor: ref.watch(authInterceptorProvider),
    tenantInterceptor: ref.watch(tenantInterceptorProvider),
  );
});

/// Convenience provider that exposes the underlying [Dio] instance.
final dioProvider = Provider<Dio>((ref) {
  return ref.watch(apiClientProvider).dio;
});

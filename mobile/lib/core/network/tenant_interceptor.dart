import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../constants/app_constants.dart';

/// Dio interceptor that injects the `X-Tenant-ID` header on every outgoing
/// request so the backend can resolve the active tenant context.
///
/// The tenant ID is read from secure storage where it is persisted after a
/// successful login. If no tenant ID is stored the header is omitted and the
/// backend will fall back to subdomain or JWT claim resolution.
class TenantInterceptor extends Interceptor {
  TenantInterceptor({
    FlutterSecureStorage? secureStorage,
  }) : _secureStorage = secureStorage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _secureStorage;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final tenantId =
        await _secureStorage.read(key: AppConstants.tenantIdKey);
    if (tenantId != null && tenantId.isNotEmpty) {
      options.headers['X-Tenant-ID'] = tenantId;
    }
    handler.next(options);
  }
}

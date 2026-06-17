import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../constants/app_constants.dart';

/// Dio interceptor that attaches the `X-Tenant-ID` header to every outgoing
/// request so the Laravel backend can resolve the active tenant context.
///
/// The tenant ID is read from secure storage where it is persisted after a
/// successful login. If no tenant ID is stored the header is omitted (e.g.,
/// during the initial login request itself).
class TenantInterceptor extends Interceptor {
  final FlutterSecureStorage _secureStorage;

  TenantInterceptor(Ref ref) : _secureStorage = const FlutterSecureStorage();

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final tenantId = await _secureStorage.read(key: AppConstants.tenantIdKey);

    if (tenantId != null && tenantId.isNotEmpty) {
      options.headers['X-Tenant-ID'] = tenantId;
    }

    handler.next(options);
  }
}

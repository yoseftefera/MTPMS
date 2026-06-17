import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/exceptions.dart';
import '../models/user_model.dart';

/// Remote data source for authentication operations.
///
/// Communicates with the Laravel backend `/api/v1/auth/*` endpoints.
abstract class AuthRemoteDataSource {
  Future<({UserModel user, String accessToken, String refreshToken})> login({
    required String email,
    required String password,
    required String tenantId,
  });

  Future<void> logout();

  Future<void> requestPasswordReset({required String email});
}

class AuthRemoteDataSourceImpl implements AuthRemoteDataSource {
  final Dio _dio;
  final FlutterSecureStorage _secureStorage;

  AuthRemoteDataSourceImpl({
    required Dio dio,
    FlutterSecureStorage? secureStorage,
  })  : _dio = dio,
        _secureStorage = secureStorage ?? const FlutterSecureStorage();

  @override
  Future<({UserModel user, String accessToken, String refreshToken})> login({
    required String email,
    required String password,
    required String tenantId,
  }) async {
    try {
      final response = await _dio.post(
        '/auth/login',
        data: {
          'email': email,
          'password': password,
        },
        options: Options(
          headers: {'X-Tenant-ID': tenantId},
        ),
      );

      final data = response.data['data'] as Map<String, dynamic>;
      final accessToken = data['access_token'] as String;
      final refreshToken = data['refresh_token'] as String;
      final userJson = data['user'] as Map<String, dynamic>;

      // Persist credentials securely.
      await Future.wait([
        _secureStorage.write(
          key: AppConstants.accessTokenKey,
          value: accessToken,
        ),
        _secureStorage.write(
          key: AppConstants.refreshTokenKey,
          value: refreshToken,
        ),
        _secureStorage.write(
          key: AppConstants.tenantIdKey,
          value: tenantId,
        ),
        _secureStorage.write(
          key: AppConstants.userIdKey,
          value: userJson['user_id'] as String,
        ),
      ]);

      return (
        user: UserModel.fromJson(userJson),
        accessToken: accessToken,
        refreshToken: refreshToken,
      );
    } on DioException catch (e) {
      _handleDioError(e);
    }
    // Unreachable — _handleDioError always throws.
  }

  @override
  Future<void> logout() async {
    try {
      await _dio.post('/auth/logout');
    } on DioException catch (e) {
      // Best-effort logout — clear local credentials regardless.
      _handleDioError(e);
    } finally {
      await Future.wait([
        _secureStorage.delete(key: AppConstants.accessTokenKey),
        _secureStorage.delete(key: AppConstants.refreshTokenKey),
        _secureStorage.delete(key: AppConstants.tenantIdKey),
        _secureStorage.delete(key: AppConstants.userIdKey),
      ]);
    }
  }

  @override
  Future<void> requestPasswordReset({required String email}) async {
    try {
      await _dio.post('/auth/password/reset', data: {'email': email});
    } on DioException catch (e) {
      _handleDioError(e);
    }
  }

  Never _handleDioError(DioException e) {
    final statusCode = e.response?.statusCode;
    final message = _extractMessage(e);

    switch (statusCode) {
      case 401:
        throw AuthException(message: message, statusCode: statusCode);
      case 403:
        throw ForbiddenException(statusCode: statusCode);
      case 404:
        throw NotFoundException(statusCode: statusCode);
      case 422:
        final errors = _extractValidationErrors(e);
        throw ValidationException(
          message: message,
          errors: errors,
          statusCode: statusCode,
        );
      case null:
        throw const NetworkException();
      default:
        throw ServerException(message: message, statusCode: statusCode);
    }
  }

  String _extractMessage(DioException e) {
    try {
      return e.response?.data['message'] as String? ??
          e.message ??
          'Unknown error';
    } catch (_) {
      return e.message ?? 'Unknown error';
    }
  }

  Map<String, List<String>> _extractValidationErrors(DioException e) {
    try {
      final errorsRaw = e.response?.data['errors'] as Map<String, dynamic>?;
      if (errorsRaw == null) return {};
      return errorsRaw.map(
        (key, value) => MapEntry(
          key,
          List<String>.from(value as List),
        ),
      );
    } catch (_) {
      return {};
    }
  }
}

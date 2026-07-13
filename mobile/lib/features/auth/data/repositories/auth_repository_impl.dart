import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/storage/hive_service.dart';
import '../../domain/entities/user.dart';
import '../../domain/repositories/auth_repository.dart';
import '../datasources/auth_remote_datasource.dart';
import '../models/user_model.dart';

class AuthRepositoryImpl implements AuthRepository {
  final AuthRemoteDataSource _remoteDataSource;
  final FlutterSecureStorage _secureStorage;

  const AuthRepositoryImpl({
    required AuthRemoteDataSource remoteDataSource,
    required FlutterSecureStorage secureStorage,
  })  : _remoteDataSource = remoteDataSource,
        _secureStorage = secureStorage;

  @override
  Future<Either<Failure, User>> login({
    required String email,
    required String password,
    required String tenantId,
  }) async {
    try {
      // Store tenant ID so TenantInterceptor can attach the header.
      await _secureStorage.write(
        key: AppConstants.tenantIdKey,
        value: tenantId,
      );

      final result = await _remoteDataSource.login(
        email: email,
        password: password,
      );

      // Persist tokens and user ID.
      await _secureStorage.write(
        key: AppConstants.accessTokenKey,
        value: result.tokens.accessToken,
      );
      if (result.tokens.refreshToken != null) {
        await _secureStorage.write(
          key: AppConstants.refreshTokenKey,
          value: result.tokens.refreshToken!,
        );
      }
      await _secureStorage.write(
        key: AppConstants.userIdKey,
        value: result.user.id,
      );

      // Cache user profile for offline use.
      await HiveService.put(
        AppConstants.authBoxName,
        'current_user',
        result.user.toJson(),
      );

      return Right(result.user);
    } on DioException catch (e) {
      if (e.response?.statusCode == 401) {
        return Left(
            AuthFailure(message: _parseError(e, 'Invalid credentials.')));
      }
      if (e.response?.statusCode == 422) {
        return Left(ValidationFailure(
          message: _parseError(e, 'Validation error.'),
          errors: _parseValidationErrors(e),
        ));
      }
      if (e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout) {
        return Left(const NetworkFailure());
      }
      return Left(ServerFailure(
        message: _parseError(e, 'Server error.'),
        statusCode: e.response?.statusCode,
      ));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, void>> logout() async {
    try {
      await _remoteDataSource.logout();
      await _secureStorage.deleteAll();
      await HiveService.clearAll();
      return const Right(null);
    } catch (e) {
      // Always clear local state even if the server call fails.
      await _secureStorage.deleteAll();
      await HiveService.clearAll();
      return const Right(null);
    }
  }

  @override
  Future<Either<Failure, User?>> getCurrentUser() async {
    try {
      final cached = HiveService.get(
        AppConstants.authBoxName,
        'current_user',
        ttl: const Duration(days: 7),
      );
      if (cached != null) {
        return Right(UserModel.fromJson(cached));
      }
      return const Right(null);
    } catch (e) {
      return Left(CacheFailure(message: e.toString()));
    }
  }

  @override
  Future<bool> isAuthenticated() async {
    final token = await _secureStorage.read(key: AppConstants.accessTokenKey);
    return token != null && token.isNotEmpty;
  }

  String _parseError(DioException e, String fallback) {
    try {
      final data = e.response?.data as Map<String, dynamic>?;
      return data?['message'] as String? ?? fallback;
    } catch (_) {
      return fallback;
    }
  }

  Map<String, List<String>> _parseValidationErrors(DioException e) {
    try {
      final data = e.response?.data as Map<String, dynamic>?;
      final errors = data?['errors'] as Map<String, dynamic>?;
      if (errors == null) return {};
      return errors.map(
        (k, v) => MapEntry(k, (v as List).map((e) => e.toString()).toList()),
      );
    } catch (_) {
      return {};
    }
  }
}

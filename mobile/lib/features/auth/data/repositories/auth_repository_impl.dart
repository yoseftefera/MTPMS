import 'package:dartz/dartz.dart';

import '../../../../core/constants/app_constants.dart';
import '../../../../core/errors/exceptions.dart';
import '../../../../core/errors/failures.dart';
import '../../../../core/storage/hive_service.dart';
import '../../domain/entities/user.dart';
import '../../domain/repositories/auth_repository.dart';
import '../datasources/auth_remote_datasource.dart';
import '../models/user_model.dart';

class AuthRepositoryImpl implements AuthRepository {
  final AuthRemoteDataSource _remoteDataSource;

  const AuthRepositoryImpl({required AuthRemoteDataSource remoteDataSource})
      : _remoteDataSource = remoteDataSource;

  @override
  Future<Either<Failure, User>> login({
    required String email,
    required String password,
    required String tenantId,
  }) async {
    try {
      final result = await _remoteDataSource.login(
        email: email,
        password: password,
        tenantId: tenantId,
      );

      // Cache the user profile for offline access.
      await HiveService.put(
        AppConstants.authBoxName,
        'current_user',
        result.user.toJson(),
      );

      return Right(result.user);
    } on AuthException catch (e) {
      return Left(AuthFailure(message: e.message));
    } on NetworkException catch (_) {
      return const Left(NetworkFailure());
    } on ValidationException catch (e) {
      return Left(ValidationFailure(message: e.message, errors: e.errors));
    } on AppException catch (e) {
      return Left(ServerFailure(message: e.message, statusCode: e.statusCode));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<Either<Failure, void>> logout() async {
    try {
      await _remoteDataSource.logout();
      await HiveService.clearAll();
      return const Right(null);
    } on AppException catch (e) {
      // Even if the server call fails, clear local data.
      await HiveService.clearAll();
      return Left(ServerFailure(message: e.message));
    } catch (e) {
      await HiveService.clearAll();
      return Left(ServerFailure(message: e.toString()));
    }
  }

  @override
  Future<User?> getCachedUser() async {
    final cached = HiveService.get(
      AppConstants.authBoxName,
      'current_user',
      ttl: AppConstants.listCacheTtl,
    );
    if (cached == null) return null;
    try {
      return UserModel.fromJson(cached);
    } catch (_) {
      return null;
    }
  }

  @override
  Future<Either<Failure, void>> requestPasswordReset({
    required String email,
  }) async {
    try {
      await _remoteDataSource.requestPasswordReset(email: email);
      return const Right(null);
    } on NetworkException catch (_) {
      return const Left(NetworkFailure());
    } on AppException catch (e) {
      return Left(ServerFailure(message: e.message, statusCode: e.statusCode));
    } catch (e) {
      return Left(ServerFailure(message: e.toString()));
    }
  }
}

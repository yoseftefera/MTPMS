import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../../../core/network/api_client.dart';
import '../../data/datasources/auth_remote_datasource.dart';
import '../../data/repositories/auth_repository_impl.dart';
import '../../domain/entities/user.dart';
import '../../domain/repositories/auth_repository.dart';
import '../../domain/usecases/login_usecase.dart';
import '../../domain/usecases/logout_usecase.dart';

// ---------------------------------------------------------------------------
// Infrastructure providers
// ---------------------------------------------------------------------------

final secureStorageProvider = Provider<FlutterSecureStorage>(
  (_) => const FlutterSecureStorage(),
);

final authRemoteDataSourceProvider = Provider<AuthRemoteDataSource>((ref) {
  return AuthRemoteDataSourceImpl(ref.watch(apiClientProvider));
});

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepositoryImpl(
    remoteDataSource: ref.watch(authRemoteDataSourceProvider),
    secureStorage: ref.watch(secureStorageProvider),
  );
});

// ---------------------------------------------------------------------------
// Use-case providers
// ---------------------------------------------------------------------------

final loginUsecaseProvider = Provider<LoginUsecase>((ref) {
  return LoginUsecase(ref.watch(authRepositoryProvider));
});

final logoutUsecaseProvider = Provider<LogoutUsecase>((ref) {
  return LogoutUsecase(ref.watch(authRepositoryProvider));
});

// ---------------------------------------------------------------------------
// Auth state notifier
// ---------------------------------------------------------------------------

/// Possible states for the authentication flow.
sealed class AuthState {
  const AuthState();
}

class AuthInitial extends AuthState {
  const AuthInitial();
}

class AuthLoading extends AuthState {
  const AuthLoading();
}

class AuthAuthenticated extends AuthState {
  final User user;
  const AuthAuthenticated(this.user);
}

class AuthUnauthenticated extends AuthState {
  const AuthUnauthenticated();
}

class AuthError extends AuthState {
  final String message;
  const AuthError(this.message);
}

class AuthNotifier extends StateNotifier<AuthState> {
  final LoginUsecase _loginUsecase;
  final LogoutUsecase _logoutUsecase;
  final AuthRepository _authRepository;

  AuthNotifier({
    required LoginUsecase loginUsecase,
    required LogoutUsecase logoutUsecase,
    required AuthRepository authRepository,
  })  : _loginUsecase = loginUsecase,
        _logoutUsecase = logoutUsecase,
        _authRepository = authRepository,
        super(const AuthInitial()) {
    _checkAuthentication();
  }

  Future<void> _checkAuthentication() async {
    final isAuth = await _authRepository.isAuthenticated();
    if (isAuth) {
      final result = await _authRepository.getCurrentUser();
      result.fold(
        (_) => state = const AuthUnauthenticated(),
        (user) => state = user != null
            ? AuthAuthenticated(user)
            : const AuthUnauthenticated(),
      );
    } else {
      state = const AuthUnauthenticated();
    }
  }

  Future<void> login({
    required String email,
    required String password,
    required String tenantId,
  }) async {
    state = const AuthLoading();
    final result = await _loginUsecase(
      email: email,
      password: password,
      tenantId: tenantId,
    );
    result.fold(
      (failure) => state = AuthError(failure.message),
      (user) => state = AuthAuthenticated(user),
    );
  }

  Future<void> logout() async {
    state = const AuthLoading();
    await _logoutUsecase();
    state = const AuthUnauthenticated();
  }
}

final authNotifierProvider =
    StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  return AuthNotifier(
    loginUsecase: ref.watch(loginUsecaseProvider),
    logoutUsecase: ref.watch(logoutUsecaseProvider),
    authRepository: ref.watch(authRepositoryProvider),
  );
});

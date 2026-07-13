import 'package:dio/dio.dart';

import '../../../../core/network/api_client.dart';
import '../models/user_model.dart';

/// Holds the tokens returned by a successful login.
class AuthTokens {
  final String accessToken;
  final String? refreshToken;

  const AuthTokens({required this.accessToken, this.refreshToken});
}

/// Holds both the authenticated user and the tokens.
class LoginResult {
  final UserModel user;
  final AuthTokens tokens;

  const LoginResult({required this.user, required this.tokens});
}

/// Remote data source for authentication endpoints.
abstract class AuthRemoteDataSource {
  Future<LoginResult> login({
    required String email,
    required String password,
  });

  Future<void> logout();
}

class AuthRemoteDataSourceImpl implements AuthRemoteDataSource {
  final ApiClient _client;

  const AuthRemoteDataSourceImpl(this._client);

  @override
  Future<LoginResult> login({
    required String email,
    required String password,
  }) async {
    final response = await _client.post(
      '/auth/login',
      data: {'email': email, 'password': password},
    );

    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    final user = UserModel.fromJson(data['user'] as Map<String, dynamic>);
    final tokens = AuthTokens(
      accessToken: data['access_token'] as String,
      refreshToken: data['refresh_token'] as String?,
    );

    return LoginResult(user: user, tokens: tokens);
  }

  @override
  Future<void> logout() async {
    try {
      await _client.post('/auth/logout');
    } on DioException {
      // Best-effort; local credentials are cleared regardless.
    }
  }
}

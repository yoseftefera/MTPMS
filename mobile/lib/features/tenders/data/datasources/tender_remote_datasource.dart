import 'package:dio/dio.dart';

import '../../../../core/errors/exceptions.dart';
import '../models/tender_model.dart';

abstract class TenderRemoteDataSource {
  Future<List<TenderModel>> getTenders({
    int page = 1,
    String? category,
    String? status,
  });

  Future<TenderModel> getTenderById(String id);
}

class TenderRemoteDataSourceImpl implements TenderRemoteDataSource {
  final Dio _dio;

  const TenderRemoteDataSourceImpl({required Dio dio}) : _dio = dio;

  @override
  Future<List<TenderModel>> getTenders({
    int page = 1,
    String? category,
    String? status,
  }) async {
    try {
      final response = await _dio.get(
        '/tenders',
        queryParameters: {
          'page': page,
          if (category != null) 'category': category,
          if (status != null) 'status': status,
        },
      );
      final items = response.data['data'] as List;
      return items
          .map((e) => TenderModel.fromJson(e as Map<String, dynamic>))
          .toList();
    } on DioException catch (e) {
      throw _mapError(e);
    }
  }

  @override
  Future<TenderModel> getTenderById(String id) async {
    try {
      final response = await _dio.get('/tenders/$id');
      return TenderModel.fromJson(
          response.data['data'] as Map<String, dynamic>);
    } on DioException catch (e) {
      throw _mapError(e);
    }
  }

  AppException _mapError(DioException e) {
    final code = e.response?.statusCode;
    final msg = e.response?.data?['message'] as String? ?? e.message ?? 'Error';
    if (code == null) return const NetworkException();
    if (code == 401) return AuthException(message: msg);
    if (code == 404) return const NotFoundException();
    return ServerException(message: msg, statusCode: code);
  }
}

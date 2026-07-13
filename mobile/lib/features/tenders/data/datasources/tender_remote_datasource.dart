import '../../../../core/network/api_client.dart';
import '../models/tender_model.dart';

abstract class TenderRemoteDataSource {
  Future<List<TenderModel>> getOpenTenders({int page = 1});
  Future<TenderModel> getTender(String tenderId);
}

class TenderRemoteDataSourceImpl implements TenderRemoteDataSource {
  final ApiClient _client;

  const TenderRemoteDataSourceImpl(this._client);

  @override
  Future<List<TenderModel>> getOpenTenders({int page = 1}) async {
    final response = await _client.get(
      '/tenders',
      queryParameters: {
        'status': 'published',
        'page': page,
        'per_page': 20,
      },
    );
    final data = (response as Map<String, dynamic>)['data'] as List<dynamic>;
    return data
        .map((e) => TenderModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<TenderModel> getTender(String tenderId) async {
    final response = await _client.get('/tenders/$tenderId');
    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return TenderModel.fromJson(data);
  }
}

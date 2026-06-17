import '../../../../core/network/api_client.dart';

abstract class BidRemoteDataSource {
  Future<Map<String, dynamic>> submitBid({
    required String tenderId,
    required Map<String, dynamic> bidData,
  });

  Future<Map<String, dynamic>> getBidForTender(String tenderId);
}

class BidRemoteDataSourceImpl implements BidRemoteDataSource {
  const BidRemoteDataSourceImpl(this._apiClient);

  final ApiClient _apiClient;

  @override
  Future<Map<String, dynamic>> submitBid({
    required String tenderId,
    required Map<String, dynamic> bidData,
  }) async {
    final data = await _apiClient.post(
      '/tenders/$tenderId/bids',
      data: bidData,
    );
    return data as Map<String, dynamic>;
  }

  @override
  Future<Map<String, dynamic>> getBidForTender(String tenderId) async {
    final data = await _apiClient.get('/tenders/$tenderId/bids/my-bid');
    return data as Map<String, dynamic>;
  }
}

import '../../../../core/network/api_client.dart';

abstract class PurchaseOrderRemoteDataSource {
  Future<Map<String, dynamic>> getPurchaseOrders({int page = 1});
  Future<Map<String, dynamic>> acceptPurchaseOrder(String id);
  Future<Map<String, dynamic>> rejectPurchaseOrder(
    String id, {
    required String reason,
  });
}

class PurchaseOrderRemoteDataSourceImpl
    implements PurchaseOrderRemoteDataSource {
  const PurchaseOrderRemoteDataSourceImpl(this._apiClient);

  final ApiClient _apiClient;

  @override
  Future<Map<String, dynamic>> getPurchaseOrders({int page = 1}) async {
    final data = await _apiClient.get(
      '/purchase-orders',
      queryParameters: {'page': page},
    );
    return data as Map<String, dynamic>;
  }

  @override
  Future<Map<String, dynamic>> acceptPurchaseOrder(String id) async {
    final data = await _apiClient.post('/purchase-orders/$id/accept');
    return data as Map<String, dynamic>;
  }

  @override
  Future<Map<String, dynamic>> rejectPurchaseOrder(
    String id, {
    required String reason,
  }) async {
    final data = await _apiClient.post(
      '/purchase-orders/$id/reject',
      data: {'reason': reason},
    );
    return data as Map<String, dynamic>;
  }
}

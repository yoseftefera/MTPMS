import '../../../../core/network/api_client.dart';
import '../models/purchase_order_model.dart';

abstract class PurchaseOrderRemoteDataSource {
  Future<List<PurchaseOrderModel>> getPurchaseOrders({int page = 1});
  Future<PurchaseOrderModel> getPurchaseOrder(String poId);
  Future<PurchaseOrderModel> acceptPurchaseOrder(String poId);
  Future<PurchaseOrderModel> rejectPurchaseOrder(
    String poId, {
    required String reason,
  });
}

class PurchaseOrderRemoteDataSourceImpl
    implements PurchaseOrderRemoteDataSource {
  final ApiClient _client;

  const PurchaseOrderRemoteDataSourceImpl(this._client);

  @override
  Future<List<PurchaseOrderModel>> getPurchaseOrders({int page = 1}) async {
    final response = await _client.get(
      '/purchase-orders',
      queryParameters: {'page': page, 'per_page': 20},
    );
    final data = (response as Map<String, dynamic>)['data'] as List<dynamic>;
    return data
        .map((e) => PurchaseOrderModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<PurchaseOrderModel> getPurchaseOrder(String poId) async {
    final response = await _client.get('/purchase-orders/$poId');
    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return PurchaseOrderModel.fromJson(data);
  }

  @override
  Future<PurchaseOrderModel> acceptPurchaseOrder(String poId) async {
    final response = await _client.post('/purchase-orders/$poId/accept');
    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return PurchaseOrderModel.fromJson(data);
  }

  @override
  Future<PurchaseOrderModel> rejectPurchaseOrder(
    String poId, {
    required String reason,
  }) async {
    final response = await _client.post(
      '/purchase-orders/$poId/reject',
      data: {'reason': reason},
    );
    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return PurchaseOrderModel.fromJson(data);
  }
}

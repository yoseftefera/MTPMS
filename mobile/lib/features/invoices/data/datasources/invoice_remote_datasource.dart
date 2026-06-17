import '../../../../core/network/api_client.dart';

abstract class InvoiceRemoteDataSource {
  Future<Map<String, dynamic>> getInvoices({int page = 1});
  Future<Map<String, dynamic>> submitInvoice(Map<String, dynamic> invoiceData);
}

class InvoiceRemoteDataSourceImpl implements InvoiceRemoteDataSource {
  const InvoiceRemoteDataSourceImpl(this._apiClient);

  final ApiClient _apiClient;

  @override
  Future<Map<String, dynamic>> getInvoices({int page = 1}) async {
    final data = await _apiClient.get(
      '/invoices',
      queryParameters: {'page': page},
    );
    return data as Map<String, dynamic>;
  }

  @override
  Future<Map<String, dynamic>> submitInvoice(
    Map<String, dynamic> invoiceData,
  ) async {
    final data = await _apiClient.post('/invoices', data: invoiceData);
    return data as Map<String, dynamic>;
  }
}

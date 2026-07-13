import '../../../../core/network/api_client.dart';
import '../models/invoice_model.dart';

abstract class InvoiceRemoteDataSource {
  Future<List<InvoiceModel>> getInvoices({int page = 1});

  Future<InvoiceModel> submitInvoice({
    required String invoiceNumber,
    required double totalAmount,
    required String currency,
    String? purchaseOrderId,
    String? contractId,
    required List<Map<String, dynamic>> items,
    DateTime? invoiceDate,
    String? notes,
  });

  Future<List<PaymentModel>> getPayments({int page = 1});
}

class InvoiceRemoteDataSourceImpl implements InvoiceRemoteDataSource {
  final ApiClient _client;

  const InvoiceRemoteDataSourceImpl(this._client);

  @override
  Future<List<InvoiceModel>> getInvoices({int page = 1}) async {
    final response = await _client.get(
      '/invoices',
      queryParameters: {'page': page, 'per_page': 20},
    );
    final data = (response as Map<String, dynamic>)['data'] as List<dynamic>;
    return data
        .map((e) => InvoiceModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<InvoiceModel> submitInvoice({
    required String invoiceNumber,
    required double totalAmount,
    required String currency,
    String? purchaseOrderId,
    String? contractId,
    required List<Map<String, dynamic>> items,
    DateTime? invoiceDate,
    String? notes,
  }) async {
    final response = await _client.post(
      '/invoices',
      data: {
        'invoice_number': invoiceNumber,
        'total_amount': totalAmount.toString(),
        'currency': currency,
        if (purchaseOrderId != null) 'purchase_order_id': purchaseOrderId,
        if (contractId != null) 'contract_id': contractId,
        'items': items,
        if (invoiceDate != null)
          'invoice_date': invoiceDate.toIso8601String().substring(0, 10),
        if (notes != null) 'notes': notes,
      },
    );
    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return InvoiceModel.fromJson(data);
  }

  @override
  Future<List<PaymentModel>> getPayments({int page = 1}) async {
    final response = await _client.get(
      '/payments',
      queryParameters: {'page': page, 'per_page': 20},
    );
    final data = (response as Map<String, dynamic>)['data'] as List<dynamic>;
    return data
        .map((e) => PaymentModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }
}

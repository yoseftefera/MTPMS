import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/invoice.dart';
import '../repositories/invoice_repository.dart';

class GetInvoices {
  final InvoiceRepository _repository;

  const GetInvoices(this._repository);

  Future<Either<Failure, List<Invoice>>> call({int page = 1}) =>
      _repository.getInvoices(page: page);
}

class SubmitInvoice {
  final InvoiceRepository _repository;

  const SubmitInvoice(this._repository);

  Future<Either<Failure, Invoice>> call({
    required String invoiceNumber,
    required double totalAmount,
    required String currency,
    String? purchaseOrderId,
    String? contractId,
    required List<Map<String, dynamic>> items,
    DateTime? invoiceDate,
    String? notes,
  }) =>
      _repository.submitInvoice(
        invoiceNumber: invoiceNumber,
        totalAmount: totalAmount,
        currency: currency,
        purchaseOrderId: purchaseOrderId,
        contractId: contractId,
        items: items,
        invoiceDate: invoiceDate,
        notes: notes,
      );
}

class GetPayments {
  final InvoiceRepository _repository;

  const GetPayments(this._repository);

  Future<Either<Failure, List<Payment>>> call({int page = 1}) =>
      _repository.getPayments(page: page);
}

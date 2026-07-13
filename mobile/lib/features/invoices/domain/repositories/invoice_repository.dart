import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/invoice.dart';

abstract class InvoiceRepository {
  Future<Either<Failure, List<Invoice>>> getInvoices({int page = 1});

  Future<Either<Failure, Invoice>> submitInvoice({
    required String invoiceNumber,
    required double totalAmount,
    required String currency,
    String? purchaseOrderId,
    String? contractId,
    required List<Map<String, dynamic>> items,
    DateTime? invoiceDate,
    String? notes,
  });

  Future<Either<Failure, List<Payment>>> getPayments({int page = 1});
}

import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/invoice.dart';

abstract class InvoiceRepository {
  Future<Either<Failure, List<Invoice>>> getInvoices({
    int page = 1,
    int perPage = 20,
  });

  Future<Either<Failure, Invoice>> getInvoiceById(String id);

  /// Submits a new invoice referencing a PO or contract.
  Future<Either<Failure, Invoice>> submitInvoice({
    required String invoiceNumber,
    required String? purchaseOrderId,
    required String? contractId,
    required double totalAmount,
    required String currency,
    required DateTime invoiceDate,
    required DateTime dueDate,
  });
}

import 'package:equatable/equatable.dart';

/// Domain entity representing a supplier invoice.
class Invoice extends Equatable {
  final String id;
  final String tenantId;
  final String invoiceNumber;
  final String supplierId;
  final String? purchaseOrderId;
  final String? contractId;
  final double totalAmount;
  final double paidAmount;
  final String currency;
  final String status; // pending | under_review | approved | paid | partially_paid | rejected
  final DateTime? dueDate;

  const Invoice({
    required this.id,
    required this.tenantId,
    required this.invoiceNumber,
    required this.supplierId,
    this.purchaseOrderId,
    this.contractId,
    required this.totalAmount,
    required this.paidAmount,
    required this.currency,
    required this.status,
    this.dueDate,
  });

  @override
  List<Object?> get props => [id, tenantId, invoiceNumber];
}

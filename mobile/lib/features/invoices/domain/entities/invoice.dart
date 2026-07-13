import 'package:equatable/equatable.dart';

class InvoiceItem extends Equatable {
  final String description;
  final double quantity;
  final double unitPrice;
  final double totalPrice;

  const InvoiceItem({
    required this.description,
    required this.quantity,
    required this.unitPrice,
    required this.totalPrice,
  });

  @override
  List<Object?> get props => [description, quantity, unitPrice, totalPrice];
}

class Payment extends Equatable {
  final String id;
  final double amount;
  final String currency;
  final String paymentMethod;
  final String status;
  final String? paymentReference;
  final DateTime? paymentDate;
  final DateTime? dueDate;

  const Payment({
    required this.id,
    required this.amount,
    required this.currency,
    required this.paymentMethod,
    required this.status,
    this.paymentReference,
    this.paymentDate,
    this.dueDate,
  });

  @override
  List<Object?> get props => [
        id,
        amount,
        currency,
        paymentMethod,
        status,
        paymentReference,
        paymentDate,
        dueDate,
      ];
}

class Invoice extends Equatable {
  final String id;
  final String invoiceNumber;
  final String? purchaseOrderId;
  final String? purchaseOrderNumber;
  final String? contractId;
  final double totalAmount;
  final double paidAmount;
  final String currency;
  final String status;
  final DateTime? invoiceDate;
  final DateTime? dueDate;
  final List<InvoiceItem> items;
  final List<Payment> payments;

  const Invoice({
    required this.id,
    required this.invoiceNumber,
    this.purchaseOrderId,
    this.purchaseOrderNumber,
    this.contractId,
    required this.totalAmount,
    required this.paidAmount,
    required this.currency,
    required this.status,
    this.invoiceDate,
    this.dueDate,
    this.items = const [],
    this.payments = const [],
  });

  double get outstandingAmount => totalAmount - paidAmount;

  @override
  List<Object?> get props => [
        id,
        invoiceNumber,
        purchaseOrderId,
        purchaseOrderNumber,
        contractId,
        totalAmount,
        paidAmount,
        currency,
        status,
        invoiceDate,
        dueDate,
        items,
        payments,
      ];
}

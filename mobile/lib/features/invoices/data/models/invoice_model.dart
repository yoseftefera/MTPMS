import '../../domain/entities/invoice.dart';

class InvoiceItemModel extends InvoiceItem {
  const InvoiceItemModel({
    required super.description,
    required super.quantity,
    required super.unitPrice,
    required super.totalPrice,
  });

  factory InvoiceItemModel.fromJson(Map<String, dynamic> json) {
    return InvoiceItemModel(
      description: json['description'] as String,
      quantity: double.tryParse(json['quantity']?.toString() ?? '1') ?? 1,
      unitPrice: double.tryParse(json['unit_price']?.toString() ?? '0') ?? 0,
      totalPrice: double.tryParse(json['total_price']?.toString() ?? '0') ?? 0,
    );
  }

  Map<String, dynamic> toJson() => {
        'description': description,
        'quantity': quantity.toString(),
        'unit_price': unitPrice.toString(),
        'total_price': totalPrice.toString(),
      };
}

class PaymentModel extends Payment {
  const PaymentModel({
    required super.id,
    required super.amount,
    required super.currency,
    required super.paymentMethod,
    required super.status,
    super.paymentReference,
    super.paymentDate,
    super.dueDate,
  });

  factory PaymentModel.fromJson(Map<String, dynamic> json) {
    return PaymentModel(
      id: json['id'] as String,
      amount: double.tryParse(json['amount']?.toString() ?? '0') ?? 0,
      currency: json['currency'] as String? ?? 'USD',
      paymentMethod: json['payment_method'] as String? ?? 'bank_transfer',
      status: json['status'] as String? ?? 'pending',
      paymentReference: json['payment_reference'] as String?,
      paymentDate: json['payment_date'] != null
          ? DateTime.tryParse(json['payment_date'] as String)
          : null,
      dueDate: json['due_date'] != null
          ? DateTime.tryParse(json['due_date'] as String)
          : null,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'amount': amount.toString(),
        'currency': currency,
        'payment_method': paymentMethod,
        'status': status,
        'payment_reference': paymentReference,
        'payment_date': paymentDate?.toIso8601String(),
        'due_date': dueDate?.toIso8601String(),
      };
}

class InvoiceModel extends Invoice {
  const InvoiceModel({
    required super.id,
    required super.invoiceNumber,
    super.purchaseOrderId,
    super.purchaseOrderNumber,
    super.contractId,
    required super.totalAmount,
    required super.paidAmount,
    required super.currency,
    required super.status,
    super.invoiceDate,
    super.dueDate,
    super.items,
    super.payments,
  });

  factory InvoiceModel.fromJson(Map<String, dynamic> json) {
    final rawItems = json['items'] as List<dynamic>? ?? [];
    final rawPayments = json['payments'] as List<dynamic>? ?? [];

    return InvoiceModel(
      id: json['id'] as String,
      invoiceNumber: json['invoice_number'] as String,
      purchaseOrderId: json['purchase_order_id'] as String?,
      purchaseOrderNumber: (json['purchase_order']
          as Map<String, dynamic>?)?['po_number'] as String?,
      contractId: json['contract_id'] as String?,
      totalAmount:
          double.tryParse(json['total_amount']?.toString() ?? '0') ?? 0,
      paidAmount: double.tryParse(json['paid_amount']?.toString() ?? '0') ?? 0,
      currency: json['currency'] as String? ?? 'USD',
      status: json['status'] as String? ?? 'draft',
      invoiceDate: json['invoice_date'] != null
          ? DateTime.tryParse(json['invoice_date'] as String)
          : null,
      dueDate: json['due_date'] != null
          ? DateTime.tryParse(json['due_date'] as String)
          : null,
      items: rawItems
          .map((e) => InvoiceItemModel.fromJson(e as Map<String, dynamic>))
          .toList(),
      payments: rawPayments
          .map((e) => PaymentModel.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'invoice_number': invoiceNumber,
        'purchase_order_id': purchaseOrderId,
        'contract_id': contractId,
        'total_amount': totalAmount.toString(),
        'paid_amount': paidAmount.toString(),
        'currency': currency,
        'status': status,
        'invoice_date': invoiceDate?.toIso8601String(),
        'due_date': dueDate?.toIso8601String(),
        'items': items.map((i) => (i as InvoiceItemModel).toJson()).toList(),
        'payments': payments.map((p) => (p as PaymentModel).toJson()).toList(),
      };
}

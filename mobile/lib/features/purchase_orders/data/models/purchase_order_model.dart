import '../../domain/entities/purchase_order.dart';

class PurchaseOrderItemModel extends PurchaseOrderItem {
  const PurchaseOrderItemModel({
    required super.id,
    required super.description,
    required super.quantity,
    required super.unitOfMeasure,
    required super.unitPrice,
    required super.totalPrice,
  });

  factory PurchaseOrderItemModel.fromJson(Map<String, dynamic> json) {
    return PurchaseOrderItemModel(
      id: json['id'] as String,
      description: json['description'] as String,
      quantity: double.tryParse(json['quantity']?.toString() ?? '0') ?? 0,
      unitOfMeasure: json['unit_of_measure'] as String? ?? '',
      unitPrice: double.tryParse(json['unit_price']?.toString() ?? '0') ?? 0,
      totalPrice: double.tryParse(json['total_price']?.toString() ?? '0') ?? 0,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'description': description,
        'quantity': quantity.toString(),
        'unit_of_measure': unitOfMeasure,
        'unit_price': unitPrice.toString(),
        'total_price': totalPrice.toString(),
      };
}

class PurchaseOrderModel extends PurchaseOrder {
  const PurchaseOrderModel({
    required super.id,
    required super.poNumber,
    required super.supplierName,
    required super.status,
    required super.totalAmount,
    required super.currency,
    required super.deliveryAddress,
    required super.requiredDeliveryDate,
    super.issuedAt,
    super.items,
  });

  factory PurchaseOrderModel.fromJson(Map<String, dynamic> json) {
    final rawItems = json['items'] as List<dynamic>? ?? [];
    return PurchaseOrderModel(
      id: json['id'] as String,
      poNumber: json['po_number'] as String,
      supplierName: (json['supplier']
              as Map<String, dynamic>?)?['organization_name'] as String? ??
          '',
      status: json['status'] as String,
      totalAmount:
          double.tryParse(json['total_amount']?.toString() ?? '0') ?? 0,
      currency: json['currency'] as String? ?? 'USD',
      deliveryAddress: json['delivery_address'] as String? ?? '',
      requiredDeliveryDate:
          DateTime.parse(json['required_delivery_date'] as String),
      issuedAt: json['issued_at'] != null
          ? DateTime.tryParse(json['issued_at'] as String)
          : null,
      items: rawItems
          .map(
              (e) => PurchaseOrderItemModel.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'po_number': poNumber,
        'supplier': {'organization_name': supplierName},
        'status': status,
        'total_amount': totalAmount.toString(),
        'currency': currency,
        'delivery_address': deliveryAddress,
        'required_delivery_date': requiredDeliveryDate.toIso8601String(),
        'issued_at': issuedAt?.toIso8601String(),
        'items':
            items.map((i) => (i as PurchaseOrderItemModel).toJson()).toList(),
      };
}

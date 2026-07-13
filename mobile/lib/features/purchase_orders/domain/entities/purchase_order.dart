import 'package:equatable/equatable.dart';

class PurchaseOrderItem extends Equatable {
  final String id;
  final String description;
  final double quantity;
  final String unitOfMeasure;
  final double unitPrice;
  final double totalPrice;

  const PurchaseOrderItem({
    required this.id,
    required this.description,
    required this.quantity,
    required this.unitOfMeasure,
    required this.unitPrice,
    required this.totalPrice,
  });

  @override
  List<Object?> get props =>
      [id, description, quantity, unitOfMeasure, unitPrice, totalPrice];
}

class PurchaseOrder extends Equatable {
  final String id;
  final String poNumber;
  final String supplierName;
  final String status;
  final double totalAmount;
  final String currency;
  final String deliveryAddress;
  final DateTime requiredDeliveryDate;
  final DateTime? issuedAt;
  final List<PurchaseOrderItem> items;

  const PurchaseOrder({
    required this.id,
    required this.poNumber,
    required this.supplierName,
    required this.status,
    required this.totalAmount,
    required this.currency,
    required this.deliveryAddress,
    required this.requiredDeliveryDate,
    this.issuedAt,
    this.items = const [],
  });

  bool get canRespond => status == 'issued';

  @override
  List<Object?> get props => [
        id,
        poNumber,
        supplierName,
        status,
        totalAmount,
        currency,
        deliveryAddress,
        requiredDeliveryDate,
        issuedAt,
        items,
      ];
}

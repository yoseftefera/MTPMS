import 'package:equatable/equatable.dart';

/// Domain entity representing a purchase order issued to a supplier.
class PurchaseOrder extends Equatable {
  final String id;
  final String tenantId;
  final String poNumber;
  final String supplierId;
  final String status;
  final double totalAmount;
  final String currency;
  final String deliveryAddress;
  final DateTime requiredDeliveryDate;
  final DateTime? issuedAt;
  final DateTime? acceptedAt;

  const PurchaseOrder({
    required this.id,
    required this.tenantId,
    required this.poNumber,
    required this.supplierId,
    required this.status,
    required this.totalAmount,
    required this.currency,
    required this.deliveryAddress,
    required this.requiredDeliveryDate,
    this.issuedAt,
    this.acceptedAt,
  });

  @override
  List<Object?> get props => [id, tenantId, poNumber];
}

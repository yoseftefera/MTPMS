import '../../domain/entities/bid.dart';

class BidModel extends Bid {
  const BidModel({
    required super.id,
    required super.tenderId,
    required super.supplierId,
    required super.totalAmount,
    required super.currency,
    required super.deliveryDays,
    super.technicalNotes,
    required super.status,
    super.submittedAt,
  });

  factory BidModel.fromJson(Map<String, dynamic> json) {
    return BidModel(
      id: json['id'] as String,
      tenderId: json['tender_id'] as String,
      supplierId: json['supplier_id'] as String,
      totalAmount:
          double.tryParse(json['total_amount']?.toString() ?? '0') ?? 0,
      currency: json['currency'] as String? ?? 'USD',
      deliveryDays: (json['delivery_days'] as num?)?.toInt() ?? 0,
      technicalNotes: json['technical_notes'] as String?,
      status: json['status'] as String? ?? 'draft',
      submittedAt: json['submitted_at'] != null
          ? DateTime.tryParse(json['submitted_at'] as String)
          : null,
    );
  }
}

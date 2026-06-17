import 'package:equatable/equatable.dart';

enum BidStatus { draft, submitted, underEvaluation, won, lost, disqualified }

/// Domain entity representing a supplier's bid on a tender.
class Bid extends Equatable {
  final String id;
  final String tenderId;
  final String supplierId;
  final double totalAmount;
  final String currency;
  final int deliveryDays;
  final String? technicalNotes;
  final BidStatus status;
  final DateTime? submittedAt;

  const Bid({
    required this.id,
    required this.tenderId,
    required this.supplierId,
    required this.totalAmount,
    required this.currency,
    required this.deliveryDays,
    this.technicalNotes,
    required this.status,
    this.submittedAt,
  });

  @override
  List<Object?> get props =>
      [id, tenderId, supplierId, totalAmount, status, submittedAt];
}

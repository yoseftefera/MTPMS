import 'package:equatable/equatable.dart';

class Bid extends Equatable {
  final String id;
  final String tenderId;
  final String supplierId;
  final double totalAmount;
  final String currency;
  final int deliveryDays;
  final String? technicalNotes;
  final String status;
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
  List<Object?> get props => [
        id,
        tenderId,
        supplierId,
        totalAmount,
        currency,
        deliveryDays,
        technicalNotes,
        status,
        submittedAt,
      ];
}

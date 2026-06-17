import 'package:equatable/equatable.dart';

/// Domain entity representing a procurement tender.
class Tender extends Equatable {
  final String id;
  final String tenantId;
  final String referenceNumber;
  final String title;
  final String description;
  final String category;
  final String tenderType; // open | restricted | single_source
  final double estimatedValue;
  final String currency;
  final DateTime submissionDeadline;
  final String status; // draft | published | closed | awarded | cancelled
  final DateTime? publishedAt;

  const Tender({
    required this.id,
    required this.tenantId,
    required this.referenceNumber,
    required this.title,
    required this.description,
    required this.category,
    required this.tenderType,
    required this.estimatedValue,
    required this.currency,
    required this.submissionDeadline,
    required this.status,
    this.publishedAt,
  });

  bool get isOpen => status == 'published' &&
      submissionDeadline.isAfter(DateTime.now());

  @override
  List<Object?> get props => [id, tenantId, referenceNumber];
}

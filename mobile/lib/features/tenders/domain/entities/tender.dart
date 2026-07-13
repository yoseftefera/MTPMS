import 'package:equatable/equatable.dart';

/// Domain entity representing a published tender.
class Tender extends Equatable {
  final String id;
  final String referenceNumber;
  final String title;
  final String description;
  final String category;
  final String tenderType;
  final double estimatedValue;
  final String currency;
  final DateTime submissionDeadline;
  final String status;
  final DateTime? publishedAt;

  const Tender({
    required this.id,
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

  bool get isOpen => status == 'published' && !isDeadlinePassed;
  bool get isDeadlinePassed => submissionDeadline.isBefore(DateTime.now());

  @override
  List<Object?> get props => [
        id,
        referenceNumber,
        title,
        description,
        category,
        tenderType,
        estimatedValue,
        currency,
        submissionDeadline,
        status,
        publishedAt,
      ];
}

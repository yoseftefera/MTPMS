import '../../domain/entities/tender.dart';

class TenderModel extends Tender {
  const TenderModel({
    required super.id,
    required super.referenceNumber,
    required super.title,
    required super.description,
    required super.category,
    required super.tenderType,
    required super.estimatedValue,
    required super.currency,
    required super.submissionDeadline,
    required super.status,
    super.publishedAt,
  });

  factory TenderModel.fromJson(Map<String, dynamic> json) {
    return TenderModel(
      id: json['id'] as String,
      referenceNumber: json['reference_number'] as String,
      title: json['title'] as String,
      description: json['description'] as String? ?? '',
      category: json['category'] as String,
      tenderType: json['tender_type'] as String? ?? 'open',
      estimatedValue:
          double.tryParse(json['estimated_value']?.toString() ?? '0') ?? 0,
      currency: json['currency'] as String? ?? 'USD',
      submissionDeadline: DateTime.parse(json['submission_deadline'] as String),
      status: json['status'] as String,
      publishedAt: json['published_at'] != null
          ? DateTime.tryParse(json['published_at'] as String)
          : null,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'reference_number': referenceNumber,
        'title': title,
        'description': description,
        'category': category,
        'tender_type': tenderType,
        'estimated_value': estimatedValue.toString(),
        'currency': currency,
        'submission_deadline': submissionDeadline.toIso8601String(),
        'status': status,
        'published_at': publishedAt?.toIso8601String(),
      };
}

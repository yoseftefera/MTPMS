import 'package:flutter_test/flutter_test.dart';
import 'package:pmp_mobile/features/tenders/data/models/tender_model.dart';

void main() {
  group('TenderModel', () {
    final validJson = {
      'id': 'tender-001',
      'tenant_id': 'tenant-456',
      'reference_number': 'TND-2024-001',
      'title': 'Office Supplies Procurement',
      'description': 'Annual office supplies tender',
      'category': 'Stationery',
      'tender_type': 'open',
      'estimated_value': '50000.00',
      'currency': 'USD',
      'submission_deadline': '2025-12-31T23:59:59Z',
      'status': 'published',
      'published_at': '2025-01-01T00:00:00Z',
    };

    test('fromJson parses all fields correctly', () {
      final model = TenderModel.fromJson(validJson);

      expect(model.id, equals('tender-001'));
      expect(model.referenceNumber, equals('TND-2024-001'));
      expect(model.title, equals('Office Supplies Procurement'));
      expect(model.tenderType, equals('open'));
      expect(model.estimatedValue, equals(50000.0));
      expect(model.currency, equals('USD'));
      expect(model.status, equals('published'));
      expect(model.publishedAt, isNotNull);
    });

    test('fromJson handles null publishedAt', () {
      final json = Map<String, dynamic>.from(validJson)
        ..['published_at'] = null;
      final model = TenderModel.fromJson(json);
      expect(model.publishedAt, isNull);
    });

    test('fromJson defaults currency to USD when missing', () {
      final json = Map<String, dynamic>.from(validJson)..remove('currency');
      final model = TenderModel.fromJson(json);
      expect(model.currency, equals('USD'));
    });

    test('isOpen returns true for published tender with future deadline', () {
      final futureDeadline = DateTime.now().add(const Duration(days: 30));
      final json = Map<String, dynamic>.from(validJson)
        ..['status'] = 'published'
        ..['submission_deadline'] = futureDeadline.toIso8601String();
      final model = TenderModel.fromJson(json);
      expect(model.isOpen, isTrue);
    });

    test('isOpen returns false for closed tender', () {
      final json = Map<String, dynamic>.from(validJson)
        ..['status'] = 'closed';
      final model = TenderModel.fromJson(json);
      expect(model.isOpen, isFalse);
    });

    test('isOpen returns false when deadline has passed', () {
      final pastDeadline = DateTime.now().subtract(const Duration(days: 1));
      final json = Map<String, dynamic>.from(validJson)
        ..['status'] = 'published'
        ..['submission_deadline'] = pastDeadline.toIso8601String();
      final model = TenderModel.fromJson(json);
      expect(model.isOpen, isFalse);
    });

    test('toJson round-trips correctly', () {
      final model = TenderModel.fromJson(validJson);
      final json = model.toJson();

      expect(json['id'], equals(model.id));
      expect(json['reference_number'], equals(model.referenceNumber));
      expect(json['status'], equals(model.status));
    });
  });
}

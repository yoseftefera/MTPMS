import 'package:flutter_test/flutter_test.dart';
import 'package:pmp_mobile/features/auth/data/models/user_model.dart';

void main() {
  group('UserModel', () {
    const validJson = {
      'user_id': 'user-123',
      'tenant_id': 'tenant-456',
      'name': 'Alice Supplier',
      'email': 'alice@example.com',
      'role': 'Supplier',
      'permissions': ['submit-bid', 'manage-invoices'],
      'avatar': null,
      'phone': '+1234567890',
    };

    test('fromJson parses all fields correctly', () {
      final model = UserModel.fromJson(validJson);

      expect(model.id, equals('user-123'));
      expect(model.tenantId, equals('tenant-456'));
      expect(model.name, equals('Alice Supplier'));
      expect(model.email, equals('alice@example.com'));
      expect(model.role, equals('Supplier'));
      expect(model.permissions, containsAll(['submit-bid', 'manage-invoices']));
      expect(model.avatar, isNull);
      expect(model.phone, equals('+1234567890'));
    });

    test('fromJson handles missing optional fields', () {
      final json = Map<String, dynamic>.from(validJson)
        ..remove('avatar')
        ..remove('phone');
      final model = UserModel.fromJson(json);
      expect(model.avatar, isNull);
      expect(model.phone, isNull);
    });

    test('fromJson handles empty permissions list', () {
      final json = Map<String, dynamic>.from(validJson)
        ..['permissions'] = <String>[];
      final model = UserModel.fromJson(json);
      expect(model.permissions, isEmpty);
    });

    test('toJson round-trips correctly', () {
      final model = UserModel.fromJson(validJson);
      final json = model.toJson();

      expect(json['user_id'], equals(model.id));
      expect(json['tenant_id'], equals(model.tenantId));
      expect(json['email'], equals(model.email));
      expect(json['role'], equals(model.role));
    });

    test('two models with same id are equal', () {
      final m1 = UserModel.fromJson(validJson);
      final m2 = UserModel.fromJson(validJson);
      expect(m1, equals(m2));
    });
  });
}

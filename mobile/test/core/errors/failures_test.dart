import 'package:flutter_test/flutter_test.dart';
import 'package:pmp_mobile/core/errors/failures.dart';

void main() {
  group('Failure equality', () {
    test('ServerFailure with same message and statusCode are equal', () {
      const f1 = ServerFailure(message: 'error', statusCode: 500);
      const f2 = ServerFailure(message: 'error', statusCode: 500);
      expect(f1, equals(f2));
    });

    test('ServerFailure with different messages are not equal', () {
      const f1 = ServerFailure(message: 'error A');
      const f2 = ServerFailure(message: 'error B');
      expect(f1, isNot(equals(f2)));
    });

    test('NetworkFailure uses default message', () {
      const f = NetworkFailure();
      expect(f.message, equals('No internet connection.'));
    });

    test('ValidationFailure includes errors map in props', () {
      const f1 = ValidationFailure(
        message: 'Validation failed',
        errors: {'email': ['Required']},
      );
      const f2 = ValidationFailure(
        message: 'Validation failed',
        errors: {'email': ['Required']},
      );
      expect(f1, equals(f2));
    });

    test('Different Failure subtypes are not equal', () {
      const server = ServerFailure(message: 'error');
      const network = NetworkFailure(message: 'error');
      expect(server, isNot(equals(network)));
    });
  });
}

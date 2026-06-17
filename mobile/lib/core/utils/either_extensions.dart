import 'package:dartz/dartz.dart';

import '../errors/failures.dart';

/// Extension helpers for working with [Either] in the domain layer.
extension EitherX<L, R> on Either<L, R> {
  /// Returns the right value or throws a [StateError].
  R getOrThrow() => fold(
        (l) => throw StateError('Expected Right but got Left: $l'),
        (r) => r,
      );

  /// Returns the left value or throws a [StateError].
  L getLeftOrThrow() => fold(
        (l) => l,
        (r) => throw StateError('Expected Left but got Right: $r'),
      );
}

/// Wraps a [Future] that may throw an [Exception] into an [Either].
///
/// Any exception is mapped to a [ServerFailure].
Future<Either<Failure, T>> safeCall<T>(Future<T> Function() call) async {
  try {
    final result = await call();
    return Right(result);
  } on Failure catch (f) {
    return Left(f);
  } catch (e) {
    return Left(ServerFailure(message: e.toString()));
  }
}

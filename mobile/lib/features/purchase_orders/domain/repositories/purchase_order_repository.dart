import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/purchase_order.dart';

abstract class PurchaseOrderRepository {
  Future<Either<Failure, List<PurchaseOrder>>> getPurchaseOrders({
    int page = 1,
    int perPage = 20,
  });

  Future<Either<Failure, PurchaseOrder>> getPurchaseOrderById(String id);

  /// Accepts a purchase order on behalf of the supplier.
  Future<Either<Failure, PurchaseOrder>> acceptPurchaseOrder(String id);

  /// Rejects a purchase order with a documented reason.
  Future<Either<Failure, PurchaseOrder>> rejectPurchaseOrder(
    String id, {
    required String reason,
  });
}

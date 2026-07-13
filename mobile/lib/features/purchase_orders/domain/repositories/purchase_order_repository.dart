import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/purchase_order.dart';

abstract class PurchaseOrderRepository {
  Future<Either<Failure, List<PurchaseOrder>>> getPurchaseOrders(
      {int page = 1});
  Future<Either<Failure, PurchaseOrder>> getPurchaseOrder(String poId);
  Future<Either<Failure, PurchaseOrder>> acceptPurchaseOrder(String poId);
  Future<Either<Failure, PurchaseOrder>> rejectPurchaseOrder(
    String poId, {
    required String reason,
  });
}

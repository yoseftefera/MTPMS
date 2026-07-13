import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/purchase_order.dart';
import '../repositories/purchase_order_repository.dart';

class GetPurchaseOrders {
  final PurchaseOrderRepository _repository;

  const GetPurchaseOrders(this._repository);

  Future<Either<Failure, List<PurchaseOrder>>> call({int page = 1}) =>
      _repository.getPurchaseOrders(page: page);
}

class AcceptPurchaseOrder {
  final PurchaseOrderRepository _repository;

  const AcceptPurchaseOrder(this._repository);

  Future<Either<Failure, PurchaseOrder>> call(String poId) =>
      _repository.acceptPurchaseOrder(poId);
}

class RejectPurchaseOrder {
  final PurchaseOrderRepository _repository;

  const RejectPurchaseOrder(this._repository);

  Future<Either<Failure, PurchaseOrder>> call(String poId,
          {required String reason}) =>
      _repository.rejectPurchaseOrder(poId, reason: reason);
}

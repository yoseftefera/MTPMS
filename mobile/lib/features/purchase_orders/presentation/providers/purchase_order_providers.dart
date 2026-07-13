import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../../../core/network/network_info.dart';
import '../../../../core/storage/pending_operations_queue.dart';
import '../../data/datasources/purchase_order_remote_datasource.dart';
import '../../data/repositories/purchase_order_repository_impl.dart';
import '../../domain/entities/purchase_order.dart';
import '../../domain/repositories/purchase_order_repository.dart';
import '../../domain/usecases/get_purchase_orders.dart';

final poRemoteDataSourceProvider =
    Provider<PurchaseOrderRemoteDataSource>((ref) {
  return PurchaseOrderRemoteDataSourceImpl(ref.watch(apiClientProvider));
});

final poRepositoryProvider = Provider<PurchaseOrderRepository>((ref) {
  return PurchaseOrderRepositoryImpl(
    ref.watch(poRemoteDataSourceProvider),
    ref.watch(networkInfoProvider),
    ref.watch(pendingOperationsQueueProvider),
  );
});

final getPurchaseOrdersProvider = Provider<GetPurchaseOrders>((ref) {
  return GetPurchaseOrders(ref.watch(poRepositoryProvider));
});

final purchaseOrdersProvider = FutureProvider<List<PurchaseOrder>>((ref) async {
  final usecase = ref.watch(getPurchaseOrdersProvider);
  final result = await usecase();
  return result.fold(
    (failure) => throw Exception(failure.message),
    (orders) => orders,
  );
});

final purchaseOrderDetailProvider =
    FutureProvider.family<PurchaseOrder, String>((ref, poId) async {
  final repo = ref.watch(poRepositoryProvider);
  final result = await repo.getPurchaseOrder(poId);
  return result.fold(
    (failure) => throw Exception(failure.message),
    (order) => order,
  );
});

// ---------------------------------------------------------------------------
// PO action state
// ---------------------------------------------------------------------------

sealed class PoActionState {
  const PoActionState();
}

class PoActionInitial extends PoActionState {
  const PoActionInitial();
}

class PoActionLoading extends PoActionState {
  const PoActionLoading();
}

class PoActionSuccess extends PoActionState {
  final PurchaseOrder order;
  final String message;
  const PoActionSuccess(this.order, this.message);
}

class PoActionError extends PoActionState {
  final String message;
  const PoActionError(this.message);
}

class PoActionNotifier extends StateNotifier<PoActionState> {
  final AcceptPurchaseOrder _accept;
  final RejectPurchaseOrder _reject;
  final Ref _ref;

  PoActionNotifier(this._accept, this._reject, this._ref)
      : super(const PoActionInitial());

  Future<void> accept(String poId) async {
    state = const PoActionLoading();
    final result = await _accept(poId);
    result.fold(
      (failure) => state = PoActionError(failure.message),
      (po) {
        state = PoActionSuccess(po, 'Purchase order accepted.');
        _ref.invalidate(purchaseOrdersProvider);
      },
    );
  }

  Future<void> reject(String poId, {required String reason}) async {
    state = const PoActionLoading();
    final result = await _reject(poId, reason: reason);
    result.fold(
      (failure) => state = PoActionError(failure.message),
      (po) {
        state = PoActionSuccess(po, 'Purchase order rejected.');
        _ref.invalidate(purchaseOrdersProvider);
      },
    );
  }

  void reset() => state = const PoActionInitial();
}

final poActionNotifierProvider =
    StateNotifierProvider.autoDispose<PoActionNotifier, PoActionState>((ref) {
  final repo = ref.watch(poRepositoryProvider);
  return PoActionNotifier(
    AcceptPurchaseOrder(repo),
    RejectPurchaseOrder(repo),
    ref,
  );
});

import 'package:equatable/equatable.dart';

/// Domain entity representing the supplier's dashboard summary data.
class DashboardSummary extends Equatable {
  final int activeTendersCount;
  final int openPurchaseOrdersCount;
  final int pendingInvoicesCount;
  final int approvedInvoicesCount;
  final int paidInvoicesCount;
  final String? currency;

  const DashboardSummary({
    required this.activeTendersCount,
    required this.openPurchaseOrdersCount,
    required this.pendingInvoicesCount,
    required this.approvedInvoicesCount,
    required this.paidInvoicesCount,
    this.currency,
  });

  @override
  List<Object?> get props => [
        activeTendersCount,
        openPurchaseOrdersCount,
        pendingInvoicesCount,
        approvedInvoicesCount,
        paidInvoicesCount,
        currency,
      ];
}

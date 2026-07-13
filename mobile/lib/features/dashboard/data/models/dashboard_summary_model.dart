import '../../domain/entities/dashboard_summary.dart';

class DashboardSummaryModel extends DashboardSummary {
  const DashboardSummaryModel({
    required super.activeTendersCount,
    required super.openPurchaseOrdersCount,
    required super.pendingInvoicesCount,
    required super.approvedInvoicesCount,
    required super.paidInvoicesCount,
    super.currency,
  });

  factory DashboardSummaryModel.fromJson(Map<String, dynamic> json) {
    return DashboardSummaryModel(
      activeTendersCount: (json['active_tenders_count'] as num?)?.toInt() ?? 0,
      openPurchaseOrdersCount:
          (json['open_purchase_orders_count'] as num?)?.toInt() ?? 0,
      pendingInvoicesCount:
          (json['pending_invoices_count'] as num?)?.toInt() ?? 0,
      approvedInvoicesCount:
          (json['approved_invoices_count'] as num?)?.toInt() ?? 0,
      paidInvoicesCount: (json['paid_invoices_count'] as num?)?.toInt() ?? 0,
      currency: json['currency'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'active_tenders_count': activeTendersCount,
        'open_purchase_orders_count': openPurchaseOrdersCount,
        'pending_invoices_count': pendingInvoicesCount,
        'approved_invoices_count': approvedInvoicesCount,
        'paid_invoices_count': paidInvoicesCount,
        'currency': currency,
      };
}

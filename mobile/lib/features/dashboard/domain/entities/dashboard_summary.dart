import 'package:equatable/equatable.dart';

/// Domain entity for the supplier dashboard summary.
class DashboardSummary extends Equatable {
  final int activeTenders;
  final int pendingPurchaseOrders;
  final int pendingInvoices;
  final int unreadNotifications;
  final double totalOutstandingAmount;
  final String currency;

  const DashboardSummary({
    required this.activeTenders,
    required this.pendingPurchaseOrders,
    required this.pendingInvoices,
    required this.unreadNotifications,
    required this.totalOutstandingAmount,
    required this.currency,
  });

  @override
  List<Object?> get props => [
        activeTenders,
        pendingPurchaseOrders,
        pendingInvoices,
        unreadNotifications,
        totalOutstandingAmount,
        currency,
      ];
}

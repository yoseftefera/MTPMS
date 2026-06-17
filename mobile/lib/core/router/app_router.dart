import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/screens/login_screen.dart';
import '../../features/dashboard/presentation/screens/dashboard_screen.dart';
import '../../features/tenders/presentation/screens/tender_list_screen.dart';
import '../../features/bids/presentation/screens/bid_submission_screen.dart';
import '../../features/purchase_orders/presentation/screens/purchase_order_list_screen.dart';
import '../../features/invoices/presentation/screens/invoice_list_screen.dart';
import '../../features/notifications/presentation/screens/notifications_screen.dart';

/// Named route constants.
class AppRoutes {
  AppRoutes._();

  static const String login = '/login';
  static const String dashboard = '/dashboard';
  static const String tenders = '/tenders';
  static const String bidSubmission = '/tenders/:tenderId/bid';
  static const String purchaseOrders = '/purchase-orders';
  static const String invoices = '/invoices';
  static const String notifications = '/notifications';
}

/// Application router provider.
final appRouterProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    initialLocation: AppRoutes.login,
    debugLogDiagnostics: true,
    routes: [
      GoRoute(
        path: AppRoutes.login,
        name: 'login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: AppRoutes.dashboard,
        name: 'dashboard',
        builder: (context, state) => const DashboardScreen(),
      ),
      GoRoute(
        path: AppRoutes.tenders,
        name: 'tenders',
        builder: (context, state) => const TenderListScreen(),
        routes: [
          GoRoute(
            path: ':tenderId/bid',
            name: 'bid-submission',
            builder: (context, state) => BidSubmissionScreen(
              tenderId: state.pathParameters['tenderId']!,
            ),
          ),
        ],
      ),
      GoRoute(
        path: AppRoutes.purchaseOrders,
        name: 'purchase-orders',
        builder: (context, state) => const PurchaseOrderListScreen(),
      ),
      GoRoute(
        path: AppRoutes.invoices,
        name: 'invoices',
        builder: (context, state) => const InvoiceListScreen(),
      ),
      GoRoute(
        path: AppRoutes.notifications,
        name: 'notifications',
        builder: (context, state) => const NotificationsScreen(),
      ),
    ],
    redirect: (context, state) {
      // Auth guard will be implemented in task 20.4.
      return null;
    },
  );
});

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/providers/auth_providers.dart';
import '../../features/auth/presentation/screens/login_screen.dart';
import '../../features/bids/presentation/screens/bid_submission_screen.dart';
import '../../features/dashboard/presentation/screens/dashboard_screen.dart';
import '../../features/invoices/presentation/screens/invoice_list_screen.dart';
import '../../features/invoices/presentation/screens/invoice_submission_screen.dart';
import '../../features/invoices/presentation/screens/payment_tracking_screen.dart';
import '../../features/notifications/presentation/screens/notifications_screen.dart';
import '../../features/purchase_orders/presentation/screens/purchase_order_detail_screen.dart';
import '../../features/purchase_orders/presentation/screens/purchase_order_list_screen.dart';
import '../../features/tenders/presentation/screens/tender_detail_screen.dart';
import '../../features/tenders/presentation/screens/tender_list_screen.dart';

/// Named route path constants.
class AppRoutes {
  AppRoutes._();

  static const String login = '/login';
  static const String dashboard = '/dashboard';
  static const String tenders = '/tenders';
  static const String purchaseOrders = '/purchase-orders';
  static const String invoices = '/invoices';
  static const String submitInvoice = '/invoices/submit';
  static const String payments = '/payments';
  static const String notifications = '/notifications';
}

/// Application router with auth redirect guard.
final appRouterProvider = Provider<GoRouter>((ref) {
  // Listen to auth state for redirect logic.
  final authListenable = ref.watch(authNotifierProvider.notifier);

  return GoRouter(
    initialLocation: AppRoutes.login,
    debugLogDiagnostics: true,
    refreshListenable: GoRouterRefreshStream(authListenable),
    redirect: (context, state) {
      final authState = ref.read(authNotifierProvider);
      final isLoginPage = state.matchedLocation == AppRoutes.login;

      // Still initializing — let it through.
      if (authState is AuthInitial || authState is AuthLoading) {
        return null;
      }

      final isAuthenticated = authState is AuthAuthenticated;

      if (!isAuthenticated && !isLoginPage) {
        // Not authenticated; redirect to login.
        return AppRoutes.login;
      }

      if (isAuthenticated && isLoginPage) {
        // Already authenticated; go to dashboard.
        return AppRoutes.dashboard;
      }

      return null;
    },
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
            path: ':tenderId',
            name: 'tender-detail',
            builder: (context, state) => TenderDetailScreen(
              tenderId: state.pathParameters['tenderId']!,
            ),
          ),
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
        routes: [
          GoRoute(
            path: ':poId',
            name: 'purchase-order-detail',
            builder: (context, state) => PurchaseOrderDetailScreen(
              purchaseOrderId: state.pathParameters['poId']!,
            ),
          ),
        ],
      ),
      GoRoute(
        path: AppRoutes.invoices,
        name: 'invoices',
        builder: (context, state) => const InvoiceListScreen(),
      ),
      GoRoute(
        path: AppRoutes.submitInvoice,
        name: 'submit-invoice',
        builder: (context, state) => const InvoiceSubmissionScreen(),
      ),
      GoRoute(
        path: AppRoutes.payments,
        name: 'payments',
        builder: (context, state) => const PaymentTrackingScreen(),
      ),
      GoRoute(
        path: AppRoutes.notifications,
        name: 'notifications',
        builder: (context, state) => const NotificationsScreen(),
      ),
    ],
  );
});

/// Adapter so [GoRouter] can listen to a [StateNotifier] for refreshes.
class GoRouterRefreshStream extends ChangeNotifier {
  GoRouterRefreshStream(StateNotifier<AuthState> notifier) {
    notifier.addListener((_) {
      notifyListeners();
    });
  }
}

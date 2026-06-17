import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/network/network_info.dart';
import '../../../../core/router/app_router.dart';
import '../../../auth/presentation/providers/auth_provider.dart';
import '../widgets/offline_banner.dart';

/// Dashboard screen showing the supplier's active tenders, POs, and invoices.
///
/// Full implementation is in task 20.4. This scaffold sets up the structure.
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isOnlineAsync = ref.watch(isOnlineProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Dashboard'),
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications_outlined),
            onPressed: () => context.push(AppRoutes.notifications),
          ),
          IconButton(
            icon: const Icon(Icons.logout),
            onPressed: () => ref.read(authProvider.notifier).logout(),
          ),
        ],
      ),
      body: Column(
        children: [
          // Offline banner
          isOnlineAsync.when(
            data: (isOnline) =>
                isOnline ? const SizedBox.shrink() : const OfflineBanner(),
            loading: () => const SizedBox.shrink(),
            error: (_, __) => const SizedBox.shrink(),
          ),

          // Navigation tiles
          Expanded(
            child: GridView.count(
              crossAxisCount: 2,
              padding: const EdgeInsets.all(16),
              mainAxisSpacing: 12,
              crossAxisSpacing: 12,
              children: [
                _NavTile(
                  icon: Icons.gavel_outlined,
                  label: 'Tenders',
                  onTap: () => context.push(AppRoutes.tenders),
                ),
                _NavTile(
                  icon: Icons.shopping_cart_outlined,
                  label: 'Purchase Orders',
                  onTap: () => context.push(AppRoutes.purchaseOrders),
                ),
                _NavTile(
                  icon: Icons.receipt_long_outlined,
                  label: 'Invoices',
                  onTap: () => context.push(AppRoutes.invoices),
                ),
                _NavTile(
                  icon: Icons.notifications_outlined,
                  label: 'Notifications',
                  onTap: () => context.push(AppRoutes.notifications),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _NavTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  const _NavTile({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 40),
            const SizedBox(height: 8),
            Text(label, style: Theme.of(context).textTheme.titleSmall),
          ],
        ),
      ),
    );
  }
}

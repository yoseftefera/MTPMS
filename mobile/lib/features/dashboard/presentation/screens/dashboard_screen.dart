import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/router/app_router.dart';
import '../../../../core/widgets/error_view.dart';
import '../../../../core/widgets/offline_banner_scaffold.dart';
import '../../../auth/presentation/providers/auth_providers.dart';
import '../providers/dashboard_providers.dart';

/// Supplier dashboard showing KPI summary cards and quick navigation.
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summaryAsync = ref.watch(dashboardSummaryProvider);
    final authState = ref.watch(authNotifierProvider);

    final userName =
        authState is AuthAuthenticated ? authState.user.name : 'Supplier';

    return OfflineBannerScaffold(
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Dashboard'),
          actions: [
            IconButton(
              icon: const Icon(Icons.notifications_outlined),
              tooltip: 'Notifications',
              onPressed: () => context.go(AppRoutes.notifications),
            ),
            PopupMenuButton<String>(
              onSelected: (value) async {
                if (value == 'logout') {
                  await ref.read(authNotifierProvider.notifier).logout();
                  if (context.mounted) context.go(AppRoutes.login);
                }
              },
              itemBuilder: (_) => [
                const PopupMenuItem(value: 'logout', child: Text('Sign Out')),
              ],
            ),
          ],
        ),
        body: RefreshIndicator(
          onRefresh: () => ref.refresh(dashboardSummaryProvider.future),
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Text(
                    'Welcome, $userName',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                  ),
                ),
              ),
              summaryAsync.when(
                data: (summary) => SliverPadding(
                  padding: const EdgeInsets.all(16),
                  sliver: SliverGrid(
                    gridDelegate:
                        const SliverGridDelegateWithFixedCrossAxisCount(
                      crossAxisCount: 2,
                      crossAxisSpacing: 12,
                      mainAxisSpacing: 12,
                      childAspectRatio: 1.4,
                    ),
                    delegate: SliverChildListDelegate([
                      _KpiCard(
                        label: 'Active Tenders',
                        value: summary.activeTendersCount.toString(),
                        icon: Icons.gavel_rounded,
                        color: Colors.blue,
                        onTap: () => context.go(AppRoutes.tenders),
                      ),
                      _KpiCard(
                        label: 'Open POs',
                        value: summary.openPurchaseOrdersCount.toString(),
                        icon: Icons.shopping_cart_outlined,
                        color: Colors.green,
                        onTap: () => context.go(AppRoutes.purchaseOrders),
                      ),
                      _KpiCard(
                        label: 'Pending Invoices',
                        value: summary.pendingInvoicesCount.toString(),
                        icon: Icons.receipt_long_outlined,
                        color: Colors.orange,
                        onTap: () => context.go(AppRoutes.invoices),
                      ),
                      _KpiCard(
                        label: 'Paid Invoices',
                        value: summary.paidInvoicesCount.toString(),
                        icon: Icons.check_circle_outline_rounded,
                        color: Colors.teal,
                        onTap: () => context.go(AppRoutes.invoices),
                      ),
                    ]),
                  ),
                ),
                loading: () => const SliverFillRemaining(
                  child: Center(child: CircularProgressIndicator()),
                ),
                error: (err, _) => SliverFillRemaining(
                  child: ErrorView(
                    message: err.toString().replaceFirst('Exception: ', ''),
                    onRetry: () => ref.refresh(dashboardSummaryProvider.future),
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                  child: Text(
                    'Quick Actions',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                  ),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                sliver: SliverList(
                  delegate: SliverChildListDelegate([
                    _QuickActionTile(
                      icon: Icons.gavel_rounded,
                      title: 'View Open Tenders',
                      subtitle: 'Browse and bid on published tenders',
                      onTap: () => context.go(AppRoutes.tenders),
                    ),
                    const SizedBox(height: 8),
                    _QuickActionTile(
                      icon: Icons.shopping_cart_outlined,
                      title: 'Purchase Orders',
                      subtitle: 'Review and respond to POs',
                      onTap: () => context.go(AppRoutes.purchaseOrders),
                    ),
                    const SizedBox(height: 8),
                    _QuickActionTile(
                      icon: Icons.receipt_long_outlined,
                      title: 'Invoices & Payments',
                      subtitle: 'Submit invoices and track payments',
                      onTap: () => context.go(AppRoutes.invoices),
                    ),
                    const SizedBox(height: 8),
                    _QuickActionTile(
                      icon: Icons.notifications_outlined,
                      title: 'Notifications',
                      subtitle: 'View all alerts and updates',
                      onTap: () => context.go(AppRoutes.notifications),
                    ),
                  ]),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _KpiCard extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  const _KpiCard({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Icon(icon, color: color, size: 28),
                  Container(
                    padding: const EdgeInsets.all(4),
                    decoration: BoxDecoration(
                      color: color.withAlpha(25),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Icon(Icons.arrow_forward_ios_rounded,
                        size: 12, color: color),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                value,
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.bold,
                    ),
              ),
              Text(
                label,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _QuickActionTile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  const _QuickActionTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: Theme.of(context).colorScheme.primaryContainer,
          child: Icon(icon,
              color: Theme.of(context).colorScheme.onPrimaryContainer),
        ),
        title: Text(title),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right_rounded),
        onTap: onTap,
      ),
    );
  }
}

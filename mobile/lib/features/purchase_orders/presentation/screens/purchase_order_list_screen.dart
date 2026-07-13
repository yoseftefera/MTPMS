import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../../core/router/app_router.dart';
import '../../../../core/widgets/error_view.dart';
import '../../../../core/widgets/loading_indicator.dart';
import '../../../../core/widgets/offline_banner_scaffold.dart';
import '../../domain/entities/purchase_order.dart';
import '../providers/purchase_order_providers.dart';

/// Screen listing all purchase orders issued to this supplier with
/// accept/reject actions for POs in 'issued' status.
class PurchaseOrderListScreen extends ConsumerWidget {
  const PurchaseOrderListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ordersAsync = ref.watch(purchaseOrdersProvider);

    return OfflineBannerScaffold(
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Purchase Orders'),
        ),
        body: RefreshIndicator(
          onRefresh: () => ref.refresh(purchaseOrdersProvider.future),
          child: ordersAsync.when(
            data: (orders) {
              if (orders.isEmpty) {
                return const _EmptyOrders();
              }
              return ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: orders.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (ctx, i) => _PurchaseOrderCard(order: orders[i]),
              );
            },
            loading: () => const LoadingIndicator(),
            error: (err, _) => ErrorView(
              message: err.toString().replaceFirst('Exception: ', ''),
              onRetry: () => ref.refresh(purchaseOrdersProvider.future),
            ),
          ),
        ),
      ),
    );
  }
}

class _EmptyOrders extends StatelessWidget {
  const _EmptyOrders();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.shopping_cart_outlined,
              size: 64, color: Theme.of(context).colorScheme.onSurfaceVariant),
          const SizedBox(height: 16),
          Text('No purchase orders',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Text(
            'Purchase orders issued to you will appear here.',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}

class _PurchaseOrderCard extends ConsumerWidget {
  final PurchaseOrder order;

  const _PurchaseOrderCard({required this.order});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final actionState = ref.watch(poActionNotifierProvider);
    final isLoading = actionState is PoActionLoading;

    // Show snackbar feedback.
    ref.listen<PoActionState>(poActionNotifierProvider, (_, state) {
      if (state is PoActionSuccess) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(state.message),
            backgroundColor: Colors.green,
          ),
        );
      } else if (state is PoActionError) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(state.message),
            backgroundColor: Theme.of(context).colorScheme.error,
          ),
        );
      }
    });

    final deliveryDate =
        DateFormat('dd MMM yyyy').format(order.requiredDeliveryDate);
    final issuedDate = order.issuedAt != null
        ? DateFormat('dd MMM yyyy').format(order.issuedAt!)
        : null;
    final amount =
        '${order.currency} ${NumberFormat('#,##0.00').format(order.totalAmount)}';

    return Card(
      child: InkWell(
        onTap: () => context.push('${AppRoutes.purchaseOrders}/${order.id}'),
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      order.poNumber,
                      style: Theme.of(context)
                          .textTheme
                          .titleMedium
                          ?.copyWith(fontWeight: FontWeight.w600),
                    ),
                  ),
                  _PoStatusChip(status: order.status),
                ],
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  const Icon(Icons.monetization_on_outlined, size: 16),
                  const SizedBox(width: 4),
                  Text(amount,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          )),
                ],
              ),
              const SizedBox(height: 4),
              Row(
                children: [
                  const Icon(Icons.local_shipping_outlined, size: 16),
                  const SizedBox(width: 4),
                  Text('Delivery by: $deliveryDate',
                      style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
              if (issuedDate != null) ...[
                const SizedBox(height: 4),
                Row(
                  children: [
                    const Icon(Icons.calendar_today_outlined, size: 16),
                    const SizedBox(width: 4),
                    Text('Issued: $issuedDate',
                        style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              ],
              if (order.items.isNotEmpty) ...[
                const SizedBox(height: 8),
                const Divider(),
                Text(
                  '${order.items.length} line item${order.items.length == 1 ? '' : 's'}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                ),
              ],
              if (order.canRespond) ...[
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: isLoading
                            ? null
                            : () => _confirmReject(context, ref),
                        icon: const Icon(Icons.close_rounded),
                        label: const Text('Reject'),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: Theme.of(context).colorScheme.error,
                          side: BorderSide(
                              color: Theme.of(context).colorScheme.error),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton.icon(
                        onPressed: isLoading
                            ? null
                            : () => ref
                                .read(poActionNotifierProvider.notifier)
                                .accept(order.id),
                        icon: const Icon(Icons.check_rounded),
                        label: const Text('Accept'),
                      ),
                    ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _confirmReject(BuildContext context, WidgetRef ref) async {
    final reasonController = TextEditingController();
    final formKey = GlobalKey<FormState>();

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Reject Purchase Order'),
        content: Form(
          key: formKey,
          child: TextFormField(
            controller: reasonController,
            decoration: const InputDecoration(
              labelText: 'Reason for rejection *',
              alignLabelWithHint: true,
            ),
            maxLines: 3,
            validator: (v) =>
                (v == null || v.trim().isEmpty) ? 'Reason is required' : null,
          ),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Cancel')),
          FilledButton(
            onPressed: () {
              if (formKey.currentState!.validate()) {
                Navigator.of(ctx).pop(true);
              }
            },
            style: FilledButton.styleFrom(
                backgroundColor: Theme.of(ctx).colorScheme.error),
            child: const Text('Reject'),
          ),
        ],
      ),
    );

    if (confirmed == true && context.mounted) {
      await ref
          .read(poActionNotifierProvider.notifier)
          .reject(order.id, reason: reasonController.text.trim());
    }
    reasonController.dispose();
  }
}

class _PoStatusChip extends StatelessWidget {
  final String status;

  const _PoStatusChip({required this.status});

  @override
  Widget build(BuildContext context) {
    Color color;
    String label;

    switch (status) {
      case 'issued':
        color = Colors.blue;
        label = 'Issued';
      case 'accepted':
        color = Colors.green;
        label = 'Accepted';
      case 'rejected':
        color = Colors.red;
        label = 'Rejected';
      case 'partially_received':
        color = Colors.orange;
        label = 'Part. Received';
      case 'fully_received':
        color = Colors.teal;
        label = 'Received';
      case 'overdue':
        color = Colors.red;
        label = 'Overdue';
      case 'cancelled':
        color = Colors.grey;
        label = 'Cancelled';
      default:
        color = Colors.grey;
        label = status.replaceAll('_', ' ');
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withAlpha(25),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: color.withAlpha(76)),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(color: color),
      ),
    );
  }
}

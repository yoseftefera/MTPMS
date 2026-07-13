import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../../core/widgets/error_view.dart';
import '../../../../core/widgets/loading_indicator.dart';
import '../../../../core/widgets/offline_banner.dart';
import '../../domain/entities/purchase_order.dart';
import '../providers/purchase_order_providers.dart';

/// Purchase order detail screen showing line items, delivery info, and
/// accept/reject actions for POs in 'issued' status.
class PurchaseOrderDetailScreen extends ConsumerWidget {
  final String purchaseOrderId;

  const PurchaseOrderDetailScreen({super.key, required this.purchaseOrderId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final poAsync = ref.watch(purchaseOrderDetailProvider(purchaseOrderId));
    final actionState = ref.watch(poActionNotifierProvider);
    final isLoading = actionState is PoActionLoading;

    // Snackbar feedback for accept/reject actions.
    ref.listen<PoActionState>(poActionNotifierProvider, (_, state) {
      if (state is PoActionSuccess) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(state.message),
            backgroundColor: Colors.green,
          ),
        );
        Navigator.of(context).pop();
      } else if (state is PoActionError) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(state.message),
            backgroundColor: Theme.of(context).colorScheme.error,
          ),
        );
      }
    });

    return OfflineBanner(
      child: Scaffold(
        appBar: AppBar(title: const Text('Purchase Order')),
        body: poAsync.when(
          data: (order) => _PurchaseOrderDetail(
            order: order,
            isLoading: isLoading,
            onAccept: () =>
                ref.read(poActionNotifierProvider.notifier).accept(order.id),
            onReject: (reason) => ref
                .read(poActionNotifierProvider.notifier)
                .reject(order.id, reason: reason),
          ),
          loading: () => const LoadingIndicator(),
          error: (err, _) => ErrorView(
            message: err.toString().replaceFirst('Exception: ', ''),
            onRetry: () =>
                ref.refresh(purchaseOrderDetailProvider(purchaseOrderId)),
          ),
        ),
      ),
    );
  }
}

class _PurchaseOrderDetail extends StatelessWidget {
  final PurchaseOrder order;
  final bool isLoading;
  final VoidCallback onAccept;
  final ValueChanged<String> onReject;

  const _PurchaseOrderDetail({
    required this.order,
    required this.isLoading,
    required this.onAccept,
    required this.onReject,
  });

  @override
  Widget build(BuildContext context) {
    final amountStr =
        '${order.currency} ${NumberFormat('#,##0.00').format(order.totalAmount)}';
    final deliveryDateStr =
        DateFormat('dd MMM yyyy').format(order.requiredDeliveryDate);
    final issuedDateStr = order.issuedAt != null
        ? DateFormat('dd MMM yyyy').format(order.issuedAt!)
        : null;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Header card
          Card(
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
                          style:
                              Theme.of(context).textTheme.titleLarge?.copyWith(
                                    fontWeight: FontWeight.bold,
                                  ),
                        ),
                      ),
                      _PoStatusChip(status: order.status),
                    ],
                  ),
                  const SizedBox(height: 12),
                  _DetailRow(
                    icon: Icons.business_outlined,
                    label: 'Supplier',
                    value: order.supplierName,
                  ),
                  const SizedBox(height: 8),
                  _DetailRow(
                    icon: Icons.monetization_on_outlined,
                    label: 'Total Amount',
                    value: amountStr,
                    valueStyle:
                        Theme.of(context).textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                  ),
                  const SizedBox(height: 8),
                  _DetailRow(
                    icon: Icons.local_shipping_outlined,
                    label: 'Delivery By',
                    value: deliveryDateStr,
                  ),
                  if (issuedDateStr != null) ...[
                    const SizedBox(height: 8),
                    _DetailRow(
                      icon: Icons.calendar_today_outlined,
                      label: 'Issued On',
                      value: issuedDateStr,
                    ),
                  ],
                  const SizedBox(height: 8),
                  _DetailRow(
                    icon: Icons.location_on_outlined,
                    label: 'Delivery Address',
                    value: order.deliveryAddress,
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),

          // Line items
          if (order.items.isNotEmpty) ...[
            Text(
              'Line Items',
              style: Theme.of(context)
                  .textTheme
                  .titleMedium
                  ?.copyWith(fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 8),
            Card(
              child: Column(
                children: [
                  // Header row
                  Padding(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    child: Row(
                      children: [
                        Expanded(
                            flex: 3,
                            child: Text('Description',
                                style: Theme.of(context)
                                    .textTheme
                                    .labelSmall
                                    ?.copyWith(fontWeight: FontWeight.w600))),
                        Expanded(
                            child: Text('Qty',
                                style: Theme.of(context)
                                    .textTheme
                                    .labelSmall
                                    ?.copyWith(fontWeight: FontWeight.w600),
                                textAlign: TextAlign.center)),
                        Expanded(
                            flex: 2,
                            child: Text('Total',
                                style: Theme.of(context)
                                    .textTheme
                                    .labelSmall
                                    ?.copyWith(fontWeight: FontWeight.w600),
                                textAlign: TextAlign.end)),
                      ],
                    ),
                  ),
                  const Divider(height: 1),
                  ...order.items.map((item) => _LineItemRow(
                        item: item,
                        currency: order.currency,
                      )),
                ],
              ),
            ),
            const SizedBox(height: 16),
          ],

          // Actions for 'issued' POs
          if (order.canRespond) ...[
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: isLoading ? null : () => _confirmReject(context),
                    icon: const Icon(Icons.close_rounded),
                    label: const Text('Reject'),
                    style: OutlinedButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                      foregroundColor: Theme.of(context).colorScheme.error,
                      side: BorderSide(
                          color: Theme.of(context).colorScheme.error),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: isLoading ? null : onAccept,
                    icon: const Icon(Icons.check_rounded),
                    label: const Text('Accept'),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 24),
          ],
        ],
      ),
    );
  }

  Future<void> _confirmReject(BuildContext context) async {
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
            child: const Text('Cancel'),
          ),
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

    if (confirmed == true) {
      onReject(reasonController.text.trim());
    }
    reasonController.dispose();
  }
}

class _DetailRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final TextStyle? valueStyle;

  const _DetailRow({
    required this.icon,
    required this.label,
    required this.value,
    this.valueStyle,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon,
            size: 16, color: Theme.of(context).colorScheme.onSurfaceVariant),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
              Text(
                value,
                style: valueStyle ?? Theme.of(context).textTheme.bodyMedium,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _LineItemRow extends StatelessWidget {
  final PurchaseOrderItem item;
  final String currency;

  const _LineItemRow({required this.item, required this.currency});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          Expanded(
            flex: 3,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item.description,
                    style: Theme.of(context).textTheme.bodyMedium,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis),
                Text(
                  '${item.unitOfMeasure} @ $currency ${NumberFormat('#,##0.00').format(item.unitPrice)}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                ),
              ],
            ),
          ),
          Expanded(
            child: Text(
              item.quantity.toStringAsFixed(
                  item.quantity == item.quantity.truncate() ? 0 : 2),
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
          Expanded(
            flex: 2,
            child: Text(
              '$currency ${NumberFormat('#,##0.00').format(item.totalPrice)}',
              textAlign: TextAlign.end,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
        ],
      ),
    );
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

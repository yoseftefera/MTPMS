import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../../core/widgets/error_view.dart';
import '../../../../core/widgets/loading_indicator.dart';
import '../../domain/entities/invoice.dart';
import '../providers/invoice_providers.dart';

/// Displays a list of payments with their status and key details.
class PaymentTrackingScreen extends ConsumerWidget {
  const PaymentTrackingScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final paymentsAsync = ref.watch(paymentsProvider);

    final body = RefreshIndicator(
      onRefresh: () => ref.refresh(paymentsProvider.future),
      child: paymentsAsync.when(
        data: (payments) {
          if (payments.isEmpty) {
            return const _EmptyPayments();
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: payments.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (ctx, i) => _PaymentCard(payment: payments[i]),
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, _) => ErrorView(
          message: err.toString().replaceFirst('Exception: ', ''),
          onRetry: () => ref.refresh(paymentsProvider.future),
        ),
      ),
    );

    // When used standalone (direct route), wrap in Scaffold.
    // When embedded in TabBarView it does not need its own Scaffold.
    final scaffold = ModalRoute.of(context);
    if (scaffold != null && scaffold.settings.name != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Payment Tracking')),
        body: body,
      );
    }
    return body;
  }
}

class _EmptyPayments extends StatelessWidget {
  const _EmptyPayments();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.payments_outlined,
              size: 64, color: Theme.of(context).colorScheme.onSurfaceVariant),
          const SizedBox(height: 16),
          Text('No payments yet',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Text(
            'Approved invoices will generate payment records here.',
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

class _PaymentCard extends StatelessWidget {
  final Payment payment;

  const _PaymentCard({required this.payment});

  @override
  Widget build(BuildContext context) {
    final amountStr =
        '${payment.currency} ${NumberFormat('#,##0.00').format(payment.amount)}';
    final dueDateStr = payment.dueDate != null
        ? DateFormat('dd MMM yyyy').format(payment.dueDate!)
        : null;
    final paymentDateStr = payment.paymentDate != null
        ? DateFormat('dd MMM yyyy').format(payment.paymentDate!)
        : null;
    final isOverdue = payment.dueDate != null &&
        payment.dueDate!.isBefore(DateTime.now()) &&
        payment.status != 'paid';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    amountStr,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.bold,
                        ),
                  ),
                ),
                _PaymentStatusChip(
                    status: payment.status, isOverdue: isOverdue),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                const Icon(Icons.account_balance_outlined, size: 16),
                const SizedBox(width: 4),
                Text(
                  _methodLabel(payment.paymentMethod),
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
            if (payment.paymentReference != null) ...[
              const SizedBox(height: 4),
              Row(
                children: [
                  const Icon(Icons.tag_rounded, size: 16),
                  const SizedBox(width: 4),
                  Text(
                    'Ref: ${payment.paymentReference}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ],
            if (dueDateStr != null) ...[
              const SizedBox(height: 4),
              Row(
                children: [
                  Icon(
                    Icons.schedule_rounded,
                    size: 16,
                    color: isOverdue ? Colors.red : null,
                  ),
                  const SizedBox(width: 4),
                  Text(
                    'Due: $dueDateStr',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: isOverdue ? Colors.red : null,
                          fontWeight: isOverdue ? FontWeight.w600 : null,
                        ),
                  ),
                ],
              ),
            ],
            if (paymentDateStr != null) ...[
              const SizedBox(height: 4),
              Row(
                children: [
                  const Icon(Icons.check_circle_outline_rounded,
                      size: 16, color: Colors.green),
                  const SizedBox(width: 4),
                  Text(
                    'Paid on: $paymentDateStr',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Colors.green,
                        ),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  String _methodLabel(String method) {
    return switch (method) {
      'bank_transfer' => 'Bank Transfer',
      'cheque' => 'Cheque',
      'cash' => 'Cash',
      'mobile_money' => 'Mobile Money',
      _ => method.replaceAll('_', ' '),
    };
  }
}

class _PaymentStatusChip extends StatelessWidget {
  final String status;
  final bool isOverdue;

  const _PaymentStatusChip({required this.status, required this.isOverdue});

  @override
  Widget build(BuildContext context) {
    Color color;
    String label;

    if (isOverdue) {
      color = Colors.red;
      label = 'Overdue';
    } else {
      switch (status) {
        case 'pending':
          color = Colors.orange;
          label = 'Pending';
        case 'paid':
          color = Colors.green;
          label = 'Paid';
        case 'cancelled':
          color = Colors.grey;
          label = 'Cancelled';
        default:
          color = Colors.grey;
          label = status.replaceAll('_', ' ');
      }
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

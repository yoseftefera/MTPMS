import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../../core/widgets/error_view.dart';
import '../../../../core/widgets/loading_indicator.dart';
import '../../../../core/widgets/offline_banner_scaffold.dart';
import '../../domain/entities/invoice.dart';
import '../providers/invoice_providers.dart';
import 'invoice_submission_screen.dart';
import 'payment_tracking_screen.dart';

/// Screen with two tabs: Invoices list and Payment tracking.
class InvoiceListScreen extends ConsumerStatefulWidget {
  const InvoiceListScreen({super.key});

  @override
  ConsumerState<InvoiceListScreen> createState() => _InvoiceListScreenState();
}

class _InvoiceListScreenState extends ConsumerState<InvoiceListScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return OfflineBannerScaffold(
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Invoices & Payments'),
          bottom: TabBar(
            controller: _tabController,
            tabs: const [
              Tab(icon: Icon(Icons.receipt_long_outlined), text: 'Invoices'),
              Tab(icon: Icon(Icons.payments_outlined), text: 'Payments'),
            ],
          ),
          actions: [
            IconButton(
              icon: const Icon(Icons.add_rounded),
              tooltip: 'Submit Invoice',
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(
                    builder: (_) => const InvoiceSubmissionScreen()),
              ),
            ),
          ],
        ),
        body: TabBarView(
          controller: _tabController,
          children: const [
            _InvoicesTab(),
            PaymentTrackingScreen(),
          ],
        ),
      ),
    );
  }
}

class _InvoicesTab extends ConsumerWidget {
  const _InvoicesTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final invoicesAsync = ref.watch(invoicesProvider);

    return RefreshIndicator(
      onRefresh: () => ref.refresh(invoicesProvider.future),
      child: invoicesAsync.when(
        data: (invoices) {
          if (invoices.isEmpty) {
            return const _EmptyInvoices();
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: invoices.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (ctx, i) => _InvoiceCard(invoice: invoices[i]),
          );
        },
        loading: () => const LoadingIndicator(),
        error: (err, _) => ErrorView(
          message: err.toString().replaceFirst('Exception: ', ''),
          onRetry: () => ref.refresh(invoicesProvider.future),
        ),
      ),
    );
  }
}

class _EmptyInvoices extends StatelessWidget {
  const _EmptyInvoices();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.receipt_long_outlined,
              size: 64, color: Theme.of(context).colorScheme.onSurfaceVariant),
          const SizedBox(height: 16),
          Text('No invoices yet',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Text(
            'Tap + to submit your first invoice.',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
          ),
        ],
      ),
    );
  }
}

class _InvoiceCard extends StatelessWidget {
  final Invoice invoice;

  const _InvoiceCard({required this.invoice});

  @override
  Widget build(BuildContext context) {
    final amount =
        '${invoice.currency} ${NumberFormat('#,##0.00').format(invoice.totalAmount)}';
    final paid =
        '${invoice.currency} ${NumberFormat('#,##0.00').format(invoice.paidAmount)}';
    final invoiceDateStr = invoice.invoiceDate != null
        ? DateFormat('dd MMM yyyy').format(invoice.invoiceDate!)
        : null;

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
                    invoice.invoiceNumber,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                  ),
                ),
                _InvoiceStatusChip(status: invoice.status),
              ],
            ),
            if (invoice.purchaseOrderNumber != null) ...[
              const SizedBox(height: 4),
              Text(
                'PO: ${invoice.purchaseOrderNumber}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
            ],
            const SizedBox(height: 8),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Total',
                        style: Theme.of(context).textTheme.labelSmall),
                    Text(amount,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w600,
                            )),
                  ],
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text('Paid', style: Theme.of(context).textTheme.labelSmall),
                    Text(paid,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: Colors.green,
                              fontWeight: FontWeight.w600,
                            )),
                  ],
                ),
              ],
            ),
            if (invoiceDateStr != null) ...[
              const SizedBox(height: 4),
              Text(
                'Dated: $invoiceDateStr',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _InvoiceStatusChip extends StatelessWidget {
  final String status;

  const _InvoiceStatusChip({required this.status});

  @override
  Widget build(BuildContext context) {
    Color color;
    String label;

    switch (status) {
      case 'draft':
        color = Colors.grey;
        label = 'Draft';
      case 'submitted':
        color = Colors.blue;
        label = 'Submitted';
      case 'under_review':
        color = Colors.orange;
        label = 'Under Review';
      case 'approved':
        color = Colors.teal;
        label = 'Approved';
      case 'paid':
        color = Colors.green;
        label = 'Paid';
      case 'partially_paid':
        color = Colors.cyan;
        label = 'Part. Paid';
      case 'rejected':
        color = Colors.red;
        label = 'Rejected';
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

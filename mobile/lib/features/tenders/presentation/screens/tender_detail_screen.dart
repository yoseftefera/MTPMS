import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/constants/route_constants.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/date_formatter.dart';
import '../../../../core/widgets/error_view.dart';
import '../../../../core/widgets/loading_indicator.dart';
import '../../../../core/widgets/offline_banner.dart';
import '../providers/tender_providers.dart';

class TenderDetailScreen extends ConsumerWidget {
  final String tenderId;

  const TenderDetailScreen({super.key, required this.tenderId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tenderAsync = ref.watch(tenderDetailProvider(tenderId));

    return OfflineBanner(
      child: Scaffold(
        appBar: AppBar(title: const Text('Tender Details')),
        body: tenderAsync.when(
          loading: () => const LoadingIndicator(),
          error: (err, _) => ErrorView(
            message: err.toString().replaceFirst('Exception: ', ''),
            onRetry: () => ref.invalidate(tenderDetailProvider(tenderId)),
          ),
          data: (tender) => ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(tender.title,
                  style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 8),
              Text(tender.referenceNumber,
                  style: Theme.of(context).textTheme.bodySmall),
              const Divider(height: 32),
              _InfoRow(label: 'Category', value: tender.category),
              _InfoRow(
                label: 'Estimated Value',
                value: CurrencyFormatter.format(tender.estimatedValue,
                    currencyCode: tender.currency),
              ),
              _InfoRow(
                label: 'Submission Deadline',
                value: DateFormatter.formatDateTime(tender.submissionDeadline),
              ),
              _InfoRow(label: 'Type', value: tender.tenderType),
              const SizedBox(height: 16),
              Text('Description',
                  style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              Text(tender.description),
              const SizedBox(height: 32),
              if (tender.isOpen)
                ElevatedButton.icon(
                  icon: const Icon(Icons.send),
                  label: const Text('Submit Bid'),
                  onPressed: () => context.go(
                    '${RouteConstants.tenders}/$tenderId/bid',
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final String label;
  final String value;

  const _InfoRow({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 160,
            child: Text(label,
                style: const TextStyle(fontWeight: FontWeight.w600)),
          ),
          Expanded(child: Text(value)),
        ],
      ),
    );
  }
}

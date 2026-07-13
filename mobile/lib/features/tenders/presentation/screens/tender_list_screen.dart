import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../../core/widgets/error_view.dart';
import '../../../../core/widgets/loading_indicator.dart';
import '../../../../core/widgets/offline_banner_scaffold.dart';
import '../../domain/entities/tender.dart';
import '../providers/tender_providers.dart';

/// Screen displaying a list of open (published) tenders the supplier can bid on.
class TenderListScreen extends ConsumerWidget {
  const TenderListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tendersAsync = ref.watch(openTendersProvider);

    return OfflineBannerScaffold(
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Open Tenders'),
          leading: BackButton(onPressed: () => context.pop()),
        ),
        body: RefreshIndicator(
          onRefresh: () => ref.refresh(openTendersProvider.future),
          child: tendersAsync.when(
            data: (tenders) {
              if (tenders.isEmpty) {
                return const _EmptyTenders();
              }
              return ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: tenders.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (ctx, i) => _TenderCard(tender: tenders[i]),
              );
            },
            loading: () => const LoadingIndicator(),
            error: (err, _) => ErrorView(
              message: err.toString().replaceFirst('Exception: ', ''),
              onRetry: () => ref.refresh(openTendersProvider.future),
            ),
          ),
        ),
      ),
    );
  }
}

class _EmptyTenders extends StatelessWidget {
  const _EmptyTenders();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            Icons.gavel_rounded,
            size: 64,
            color: Theme.of(context).colorScheme.onSurfaceVariant,
          ),
          const SizedBox(height: 16),
          Text(
            'No open tenders',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          Text(
            'Check back later for new bidding opportunities.',
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

class _TenderCard extends StatelessWidget {
  final Tender tender;

  const _TenderCard({required this.tender});

  @override
  Widget build(BuildContext context) {
    final deadlineFormatted =
        DateFormat('dd MMM yyyy HH:mm').format(tender.submissionDeadline);
    final isPastDeadline = tender.isDeadlinePassed;

    return Card(
      child: InkWell(
        onTap: () => context.push('/tenders/${tender.id}/bid'),
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
                      tender.title,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(width: 8),
                  _StatusChip(status: tender.status),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                tender.referenceNumber,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
              const SizedBox(height: 8),
              Text(
                tender.description,
                style: Theme.of(context).textTheme.bodyMedium,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  const Icon(Icons.category_outlined, size: 16),
                  const SizedBox(width: 4),
                  Expanded(
                      child: Text(tender.category,
                          style: Theme.of(context).textTheme.bodySmall)),
                ],
              ),
              const SizedBox(height: 4),
              Row(
                children: [
                  Icon(
                    Icons.schedule_rounded,
                    size: 16,
                    color: isPastDeadline ? Colors.red : null,
                  ),
                  const SizedBox(width: 4),
                  Text(
                    'Deadline: $deadlineFormatted',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: isPastDeadline ? Colors.red : null,
                          fontWeight: isPastDeadline ? FontWeight.w600 : null,
                        ),
                  ),
                ],
              ),
              if (!isPastDeadline) ...[
                const SizedBox(height: 12),
                Align(
                  alignment: Alignment.centerRight,
                  child: FilledButton.tonal(
                    onPressed: () => context.push('/tenders/${tender.id}/bid'),
                    child: const Text('Submit Bid'),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  final String status;

  const _StatusChip({required this.status});

  @override
  Widget build(BuildContext context) {
    Color color;
    String label;

    switch (status) {
      case 'published':
        color = Colors.green;
        label = 'Open';
      case 'closed':
        color = Colors.red;
        label = 'Closed';
      case 'awarded':
        color = Colors.blue;
        label = 'Awarded';
      case 'cancelled':
        color = Colors.grey;
        label = 'Cancelled';
      default:
        color = Colors.grey;
        label = status;
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

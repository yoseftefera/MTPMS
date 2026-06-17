import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/network/network_info.dart';
import '../../../dashboard/presentation/widgets/offline_banner.dart';
import '../providers/tender_provider.dart';

/// Screen listing open tenders available for bid submission.
///
/// Full implementation is in task 20.4. This scaffold sets up the structure.
class TenderListScreen extends ConsumerWidget {
  const TenderListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tendersAsync = ref.watch(tenderListProvider(1));
    final isOnlineAsync = ref.watch(isOnlineProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Open Tenders')),
      body: Column(
        children: [
          isOnlineAsync.when(
            data: (online) =>
                online ? const SizedBox.shrink() : const OfflineBanner(),
            loading: () => const SizedBox.shrink(),
            error: (_, __) => const SizedBox.shrink(),
          ),
          Expanded(
            child: tendersAsync.when(
              data: (tenders) => tenders.isEmpty
                  ? const Center(child: Text('No open tenders.'))
                  : ListView.builder(
                      itemCount: tenders.length,
                      itemBuilder: (context, index) {
                        final tender = tenders[index];
                        return ListTile(
                          title: Text(tender.title),
                          subtitle: Text(tender.referenceNumber),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () => context.push(
                            '/tenders/${tender.id}/bid',
                          ),
                        );
                      },
                    ),
              loading: () =>
                  const Center(child: CircularProgressIndicator()),
              error: (e, _) => Center(child: Text('Error: $e')),
            ),
          ),
        ],
      ),
    );
  }
}

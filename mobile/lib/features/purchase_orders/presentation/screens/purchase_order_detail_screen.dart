import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/widgets/offline_banner.dart';

/// Purchase order detail screen with accept/reject actions.
class PurchaseOrderDetailScreen extends ConsumerWidget {
  final String purchaseOrderId;

  const PurchaseOrderDetailScreen({super.key, required this.purchaseOrderId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return OfflineBanner(
      child: Scaffold(
        appBar: AppBar(title: const Text('Purchase Order')),
        body: Center(
          // TODO(task-20.4): implement PO detail with accept/reject actions
          child: Text('PO detail for $purchaseOrderId'),
        ),
      ),
    );
  }
}

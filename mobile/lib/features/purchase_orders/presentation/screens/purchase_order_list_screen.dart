import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Screen listing purchase orders for the authenticated supplier.
///
/// Full implementation is in task 20.4.
class PurchaseOrderListScreen extends ConsumerWidget {
  const PurchaseOrderListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Purchase Orders')),
      body: const Center(
        child: Text('Purchase orders list\n(Task 20.4)'),
      ),
    );
  }
}

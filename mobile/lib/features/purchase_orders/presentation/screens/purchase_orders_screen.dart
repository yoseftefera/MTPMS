import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Purchase orders list screen (accept / reject actions).
/// Full implementation is covered in task 20.4.
class PurchaseOrdersScreen extends ConsumerWidget {
  const PurchaseOrdersScreen({super.key});

  static const routePath = '/purchase-orders';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Purchase Orders')),
      body: const Center(child: Text('Purchase Orders — coming in task 20.4')),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Screen listing invoices submitted by the authenticated supplier.
///
/// Full implementation is in task 20.4.
class InvoiceListScreen extends ConsumerWidget {
  const InvoiceListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Invoices')),
      body: const Center(
        child: Text('Invoice list\n(Task 20.4)'),
      ),
    );
  }
}

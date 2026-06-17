import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Invoice submission and payment tracking screen.
/// Full implementation is covered in task 20.4.
class InvoicesScreen extends ConsumerWidget {
  const InvoicesScreen({super.key});

  static const routePath = '/invoices';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Invoices')),
      body: const Center(child: Text('Invoices — coming in task 20.4')),
    );
  }
}

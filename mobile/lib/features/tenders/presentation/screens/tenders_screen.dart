import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Open tenders list screen for suppliers.
/// Full implementation is covered in task 20.4.
class TendersScreen extends ConsumerWidget {
  const TendersScreen({super.key});

  static const routePath = '/tenders';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Open Tenders')),
      body: const Center(child: Text('Tenders — coming in task 20.4')),
    );
  }
}

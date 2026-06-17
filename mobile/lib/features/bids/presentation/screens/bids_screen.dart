import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Bid submission and history screen.
/// Full implementation is covered in task 20.4.
class BidsScreen extends ConsumerWidget {
  const BidsScreen({super.key});

  static const routePath = '/bids';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('My Bids')),
      body: const Center(child: Text('Bids — coming in task 20.4')),
    );
  }
}

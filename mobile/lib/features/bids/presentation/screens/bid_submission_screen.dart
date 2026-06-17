import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Bid submission screen for a specific tender.
///
/// Full implementation is in task 20.4.
class BidSubmissionScreen extends ConsumerWidget {
  final String tenderId;

  const BidSubmissionScreen({super.key, required this.tenderId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Submit Bid')),
      body: Center(
        child: Text('Bid submission for tender $tenderId\n(Task 20.4)'),
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../../core/widgets/loading_indicator.dart';
import '../../../tenders/presentation/providers/tender_providers.dart';
import '../providers/bid_providers.dart';

/// Bid submission form for a specific tender.
class BidSubmissionScreen extends ConsumerStatefulWidget {
  final String tenderId;

  const BidSubmissionScreen({super.key, required this.tenderId});

  @override
  ConsumerState<BidSubmissionScreen> createState() =>
      _BidSubmissionScreenState();
}

class _BidSubmissionScreenState extends ConsumerState<BidSubmissionScreen> {
  final _formKey = GlobalKey<FormState>();
  final _amountController = TextEditingController();
  final _deliveryDaysController = TextEditingController();
  final _notesController = TextEditingController();

  final List<String> _selectedDocumentPaths = [];

  @override
  void dispose() {
    _amountController.dispose();
    _deliveryDaysController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    await ref.read(bidSubmissionNotifierProvider.notifier).submit(
          tenderId: widget.tenderId,
          totalAmount: double.parse(_amountController.text.trim()),
          deliveryDays: int.parse(_deliveryDaysController.text.trim()),
          technicalNotes: _notesController.text.trim().isNotEmpty
              ? _notesController.text.trim()
              : null,
          documentPaths:
              _selectedDocumentPaths.isNotEmpty ? _selectedDocumentPaths : null,
        );
  }

  @override
  Widget build(BuildContext context) {
    final bidState = ref.watch(bidSubmissionNotifierProvider);
    final tenderAsync = ref.watch(tenderDetailProvider(widget.tenderId));
    final isLoading = bidState is BidSubmissionLoading;

    // Show success dialog.
    ref.listen<BidSubmissionState>(bidSubmissionNotifierProvider, (_, state) {
      if (state is BidSubmissionSuccess) {
        _showSuccessDialog();
      }
    });

    return Scaffold(
      appBar: AppBar(
        title: const Text('Submit Bid'),
      ),
      body: tenderAsync.when(
        data: (tender) => SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Tender summary card
              _TenderSummaryCard(
                title: tender.title,
                referenceNumber: tender.referenceNumber,
                deadline: DateFormat('dd MMM yyyy HH:mm')
                    .format(tender.submissionDeadline),
                estimatedValue:
                    '${tender.currency} ${NumberFormat('#,##0.00').format(tender.estimatedValue)}',
              ),
              const SizedBox(height: 20),

              // Error banner
              if (bidState is BidSubmissionError) ...[
                _ErrorBanner(message: bidState.message),
                const SizedBox(height: 16),
              ],

              Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'Bid Details',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                    const SizedBox(height: 16),

                    // Bid amount
                    TextFormField(
                      controller: _amountController,
                      decoration: const InputDecoration(
                        labelText: 'Total Bid Amount *',
                        hintText: '0.00',
                        prefixIcon: Icon(Icons.attach_money_rounded),
                      ),
                      keyboardType:
                          const TextInputType.numberWithOptions(decimal: true),
                      textInputAction: TextInputAction.next,
                      validator: (v) {
                        if (v == null || v.trim().isEmpty) {
                          return 'Bid amount is required';
                        }
                        final parsed = double.tryParse(v.trim());
                        if (parsed == null || parsed <= 0) {
                          return 'Enter a valid positive amount';
                        }
                        return null;
                      },
                    ),
                    const SizedBox(height: 16),

                    // Delivery days
                    TextFormField(
                      controller: _deliveryDaysController,
                      decoration: const InputDecoration(
                        labelText: 'Delivery Days *',
                        hintText: 'e.g. 30',
                        prefixIcon: Icon(Icons.local_shipping_outlined),
                      ),
                      keyboardType: TextInputType.number,
                      textInputAction: TextInputAction.next,
                      validator: (v) {
                        if (v == null || v.trim().isEmpty) {
                          return 'Delivery days is required';
                        }
                        final parsed = int.tryParse(v.trim());
                        if (parsed == null || parsed <= 0) {
                          return 'Enter a valid number of days';
                        }
                        return null;
                      },
                    ),
                    const SizedBox(height: 16),

                    // Technical notes
                    TextFormField(
                      controller: _notesController,
                      decoration: const InputDecoration(
                        labelText: 'Technical Notes (optional)',
                        hintText:
                            'Describe your approach, qualifications, or any relevant details...',
                        alignLabelWithHint: true,
                      ),
                      maxLines: 5,
                      textInputAction: TextInputAction.newline,
                    ),
                    const SizedBox(height: 20),

                    // Document upload section
                    Text(
                      'Supporting Documents (optional)',
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                    const SizedBox(height: 8),
                    _DocumentUploadSection(
                      selectedPaths: _selectedDocumentPaths,
                      onPathsChanged: (paths) {
                        setState(() {
                          _selectedDocumentPaths
                            ..clear()
                            ..addAll(paths);
                        });
                      },
                    ),
                    const SizedBox(height: 24),

                    FilledButton(
                      onPressed: isLoading ? null : _submit,
                      child: isLoading
                          ? const SizedBox(
                              height: 20,
                              width: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : const Text('Submit Bid'),
                    ),
                    const SizedBox(height: 24),
                  ],
                ),
              ),
            ],
          ),
        ),
        loading: () => const LoadingIndicator(),
        error: (err, _) => Center(
          child: Text(err.toString().replaceFirst('Exception: ', '')),
        ),
      ),
    );
  }

  void _showSuccessDialog() {
    showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.check_circle_rounded,
            color: Colors.green, size: 48),
        title: const Text('Bid Submitted'),
        content: const Text(
          'Your bid has been successfully submitted. You will be notified of the evaluation outcome.',
        ),
        actions: [
          FilledButton(
            onPressed: () {
              Navigator.of(ctx).pop();
              Navigator.of(context).pop();
            },
            child: const Text('Done'),
          ),
        ],
      ),
    );
  }
}

class _TenderSummaryCard extends StatelessWidget {
  final String title;
  final String referenceNumber;
  final String deadline;
  final String estimatedValue;

  const _TenderSummaryCard({
    required this.title,
    required this.referenceNumber,
    required this.deadline,
    required this.estimatedValue,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      color: Theme.of(context).colorScheme.primaryContainer,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: Theme.of(context).colorScheme.onPrimaryContainer,
                  ),
            ),
            const SizedBox(height: 4),
            Text(
              referenceNumber,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: Theme.of(context).colorScheme.onPrimaryContainer,
                  ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: _InfoItem(
                    icon: Icons.schedule_rounded,
                    label: 'Deadline',
                    value: deadline,
                  ),
                ),
                Expanded(
                  child: _InfoItem(
                    icon: Icons.monetization_on_outlined,
                    label: 'Est. Value',
                    value: estimatedValue,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _InfoItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;

  const _InfoItem({
    required this.icon,
    required this.label,
    required this.value,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon,
            size: 16, color: Theme.of(context).colorScheme.onPrimaryContainer),
        const SizedBox(width: 4),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: Theme.of(context).colorScheme.onPrimaryContainer,
                    ),
              ),
              Text(
                value,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onPrimaryContainer,
                      fontWeight: FontWeight.w600,
                    ),
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _DocumentUploadSection extends StatefulWidget {
  final List<String> selectedPaths;
  final ValueChanged<List<String>> onPathsChanged;

  const _DocumentUploadSection({
    required this.selectedPaths,
    required this.onPathsChanged,
  });

  @override
  State<_DocumentUploadSection> createState() => _DocumentUploadSectionState();
}

class _DocumentUploadSectionState extends State<_DocumentUploadSection> {
  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        OutlinedButton.icon(
          onPressed: _pickDocument,
          icon: const Icon(Icons.attach_file_rounded),
          label: const Text('Attach Document'),
        ),
        if (widget.selectedPaths.isNotEmpty) ...[
          const SizedBox(height: 8),
          ...widget.selectedPaths.asMap().entries.map((entry) => Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  children: [
                    const Icon(Icons.insert_drive_file_outlined, size: 16),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        entry.value.split('/').last,
                        style: Theme.of(context).textTheme.bodySmall,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close_rounded, size: 16),
                      onPressed: () {
                        final updated = List<String>.from(widget.selectedPaths)
                          ..removeAt(entry.key);
                        widget.onPathsChanged(updated);
                      },
                    ),
                  ],
                ),
              )),
        ],
        Text(
          'Accepted formats: PDF, DOCX, XLSX, PNG, JPG (max 10 MB each)',
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
        ),
      ],
    );
  }

  Future<void> _pickDocument() async {
    // File picker integration point.
    // In production, integrate the `file_picker` package here.
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text(
            'File picker: add the file_picker package to enable document attachment.'),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  final String message;

  const _ErrorBanner({required this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Icon(Icons.error_outline_rounded,
              color: Theme.of(context).colorScheme.onErrorContainer, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: Theme.of(context).colorScheme.onErrorContainer,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}

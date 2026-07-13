import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../providers/invoice_providers.dart';

/// Screen for submitting a new invoice referencing a PO or contract.
class InvoiceSubmissionScreen extends ConsumerStatefulWidget {
  const InvoiceSubmissionScreen({super.key});

  @override
  ConsumerState<InvoiceSubmissionScreen> createState() =>
      _InvoiceSubmissionScreenState();
}

class _InvoiceSubmissionScreenState
    extends ConsumerState<InvoiceSubmissionScreen> {
  final _formKey = GlobalKey<FormState>();
  final _invoiceNumberController = TextEditingController();
  final _poIdController = TextEditingController();
  final _contractIdController = TextEditingController();
  final _notesController = TextEditingController();

  String _currency = 'USD';
  DateTime? _invoiceDate;
  final List<_LineItem> _lineItems = [_LineItem()];

  @override
  void dispose() {
    _invoiceNumberController.dispose();
    _poIdController.dispose();
    _contractIdController.dispose();
    _notesController.dispose();
    for (final item in _lineItems) {
      item.dispose();
    }
    super.dispose();
  }

  double get _totalAmount => _lineItems.fold(0.0,
      (sum, item) => sum + ((double.tryParse(item.totalController.text) ?? 0)));

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    if (_lineItems.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Add at least one line item.')),
      );
      return;
    }

    final items = _lineItems
        .map((item) => {
              'description': item.descController.text.trim(),
              'quantity': double.tryParse(item.qtyController.text) ?? 1,
              'unit_price': double.tryParse(item.priceController.text) ?? 0,
              'total_price': double.tryParse(item.totalController.text) ?? 0,
            })
        .toList();

    await ref.read(invoiceSubmissionNotifierProvider.notifier).submit(
          invoiceNumber: _invoiceNumberController.text.trim(),
          totalAmount: _totalAmount,
          currency: _currency,
          purchaseOrderId: _poIdController.text.trim().isNotEmpty
              ? _poIdController.text.trim()
              : null,
          contractId: _contractIdController.text.trim().isNotEmpty
              ? _contractIdController.text.trim()
              : null,
          items: items,
          invoiceDate: _invoiceDate,
          notes: _notesController.text.trim().isNotEmpty
              ? _notesController.text.trim()
              : null,
        );
  }

  @override
  Widget build(BuildContext context) {
    final submissionState = ref.watch(invoiceSubmissionNotifierProvider);
    final isLoading = submissionState is InvoiceSubmissionLoading;

    ref.listen<InvoiceSubmissionState>(invoiceSubmissionNotifierProvider,
        (_, state) {
      if (state is InvoiceSubmissionSuccess) {
        _showSuccessDialog();
      }
    });

    return Scaffold(
      appBar: AppBar(title: const Text('Submit Invoice')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (submissionState is InvoiceSubmissionError) ...[
                _ErrorBanner(message: submissionState.message),
                const SizedBox(height: 16),
              ],

              // --- Invoice details ---
              Text('Invoice Details',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      )),
              const SizedBox(height: 16),

              TextFormField(
                controller: _invoiceNumberController,
                decoration: const InputDecoration(
                  labelText: 'Invoice Number *',
                  prefixIcon: Icon(Icons.tag_rounded),
                ),
                textInputAction: TextInputAction.next,
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'Invoice number is required'
                    : null,
              ),
              const SizedBox(height: 16),

              // Currency selector
              DropdownButtonFormField<String>(
                initialValue: _currency,
                decoration: const InputDecoration(
                  labelText: 'Currency',
                  prefixIcon: Icon(Icons.currency_exchange_rounded),
                ),
                items: const ['USD', 'EUR', 'GBP', 'ETB', 'KES']
                    .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                    .toList(),
                onChanged: (v) => setState(() => _currency = v ?? 'USD'),
              ),
              const SizedBox(height: 16),

              // Invoice date picker
              InkWell(
                onTap: () async {
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: _invoiceDate ?? DateTime.now(),
                    firstDate: DateTime(2020),
                    lastDate: DateTime.now(),
                  );
                  if (picked != null) {
                    setState(() => _invoiceDate = picked);
                  }
                },
                child: InputDecorator(
                  decoration: const InputDecoration(
                    labelText: 'Invoice Date',
                    prefixIcon: Icon(Icons.calendar_today_outlined),
                  ),
                  child: Text(
                    _invoiceDate != null
                        ? DateFormat('dd MMM yyyy').format(_invoiceDate!)
                        : 'Select date',
                    style: _invoiceDate != null
                        ? null
                        : Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: Theme.of(context)
                                  .colorScheme
                                  .onSurfaceVariant,
                            ),
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // PO reference
              TextFormField(
                controller: _poIdController,
                decoration: const InputDecoration(
                  labelText: 'Purchase Order ID (optional)',
                  hintText: 'UUID of the related PO',
                  prefixIcon: Icon(Icons.shopping_cart_outlined),
                ),
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 16),

              // Contract reference
              TextFormField(
                controller: _contractIdController,
                decoration: const InputDecoration(
                  labelText: 'Contract ID (optional)',
                  hintText: 'UUID of the related contract',
                  prefixIcon: Icon(Icons.article_outlined),
                ),
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 24),

              // --- Line items ---
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('Line Items',
                      style: Theme.of(context)
                          .textTheme
                          .titleMedium
                          ?.copyWith(fontWeight: FontWeight.w600)),
                  TextButton.icon(
                    onPressed: () {
                      setState(() => _lineItems.add(_LineItem()));
                    },
                    icon: const Icon(Icons.add_rounded),
                    label: const Text('Add Item'),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              ..._lineItems.asMap().entries.map((entry) => _LineItemRow(
                    key: ValueKey(entry.key),
                    item: entry.value,
                    index: entry.key,
                    canDelete: _lineItems.length > 1,
                    onDelete: () =>
                        setState(() => _lineItems.removeAt(entry.key)),
                    onChanged: () => setState(() {}),
                  )),
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerRight,
                child: Text(
                  'Total: $_currency ${NumberFormat('#,##0.00').format(_totalAmount)}',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.bold,
                      ),
                ),
              ),
              const SizedBox(height: 16),

              // Notes
              TextFormField(
                controller: _notesController,
                decoration: const InputDecoration(
                  labelText: 'Notes (optional)',
                  alignLabelWithHint: true,
                ),
                maxLines: 3,
                textInputAction: TextInputAction.newline,
              ),
              const SizedBox(height: 24),

              FilledButton(
                onPressed: isLoading ? null : _submit,
                child: isLoading
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Text('Submit Invoice'),
              ),
              const SizedBox(height: 24),
            ],
          ),
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
        title: const Text('Invoice Submitted'),
        content: const Text(
          'Your invoice has been submitted and is pending review by the finance team.',
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

/// Mutable line-item data holder with associated text controllers.
class _LineItem {
  final descController = TextEditingController();
  final qtyController = TextEditingController(text: '1');
  final priceController = TextEditingController();
  final totalController = TextEditingController();

  void recalculate() {
    final qty = double.tryParse(qtyController.text) ?? 0;
    final price = double.tryParse(priceController.text) ?? 0;
    totalController.text = (qty * price).toStringAsFixed(2);
  }

  void dispose() {
    descController.dispose();
    qtyController.dispose();
    priceController.dispose();
    totalController.dispose();
  }
}

class _LineItemRow extends StatefulWidget {
  final _LineItem item;
  final int index;
  final bool canDelete;
  final VoidCallback onDelete;
  final VoidCallback onChanged;

  const _LineItemRow({
    required super.key,
    required this.item,
    required this.index,
    required this.canDelete,
    required this.onDelete,
    required this.onChanged,
  });

  @override
  State<_LineItemRow> createState() => _LineItemRowState();
}

class _LineItemRowState extends State<_LineItemRow> {
  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          children: [
            Row(
              children: [
                Text('Item ${widget.index + 1}',
                    style: Theme.of(context).textTheme.labelMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                        )),
                const Spacer(),
                if (widget.canDelete)
                  IconButton(
                    icon: const Icon(Icons.delete_outline_rounded),
                    iconSize: 20,
                    onPressed: widget.onDelete,
                    color: Theme.of(context).colorScheme.error,
                  ),
              ],
            ),
            TextFormField(
              controller: widget.item.descController,
              decoration: const InputDecoration(
                  labelText: 'Description *', isDense: true),
              validator: (v) => (v == null || v.trim().isEmpty)
                  ? 'Description is required'
                  : null,
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    controller: widget.item.qtyController,
                    decoration: const InputDecoration(
                        labelText: 'Qty *', isDense: true),
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    onChanged: (_) {
                      widget.item.recalculate();
                      widget.onChanged();
                    },
                    validator: (v) {
                      final n = double.tryParse(v ?? '');
                      if (n == null || n <= 0) return 'Invalid qty';
                      return null;
                    },
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextFormField(
                    controller: widget.item.priceController,
                    decoration: const InputDecoration(
                        labelText: 'Unit Price *', isDense: true),
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    onChanged: (_) {
                      widget.item.recalculate();
                      widget.onChanged();
                    },
                    validator: (v) {
                      final n = double.tryParse(v ?? '');
                      if (n == null || n < 0) return 'Invalid price';
                      return null;
                    },
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextFormField(
                    controller: widget.item.totalController,
                    decoration: const InputDecoration(
                        labelText: 'Total', isDense: true),
                    readOnly: true,
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

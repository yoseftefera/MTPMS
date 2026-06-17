import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Invoice submission form for suppliers.
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
  final _amountController = TextEditingController();
  final _poReferenceController = TextEditingController();
  String _currency = 'USD';
  bool _isSubmitting = false;

  @override
  void dispose() {
    _invoiceNumberController.dispose();
    _amountController.dispose();
    _poReferenceController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _isSubmitting = true);

    // TODO(task-20.4): wire up InvoiceRepository.submitInvoice
    await Future.delayed(const Duration(seconds: 1));

    if (mounted) {
      setState(() => _isSubmitting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Invoice submitted successfully.')),
      );
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Submit Invoice')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                controller: _invoiceNumberController,
                decoration:
                    const InputDecoration(labelText: 'Invoice Number'),
                validator: (v) =>
                    (v == null || v.isEmpty) ? 'Invoice number required' : null,
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _poReferenceController,
                decoration: const InputDecoration(
                  labelText: 'Purchase Order Reference (optional)',
                ),
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  SizedBox(
                    width: 100,
                    child: DropdownButtonFormField<String>(
                      value: _currency,
                      decoration:
                          const InputDecoration(labelText: 'Currency'),
                      items: ['USD', 'EUR', 'GBP', 'KES']
                          .map((c) =>
                              DropdownMenuItem(value: c, child: Text(c)))
                          .toList(),
                      onChanged: (v) => setState(() => _currency = v!),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextFormField(
                      controller: _amountController,
                      decoration:
                          const InputDecoration(labelText: 'Total Amount'),
                      keyboardType: const TextInputType.numberWithOptions(
                          decimal: true),
                      inputFormatters: [
                        FilteringTextInputFormatter.allow(
                            RegExp(r'^\d+\.?\d{0,2}')),
                      ],
                      validator: (v) {
                        if (v == null || v.isEmpty) return 'Amount required';
                        if (double.tryParse(v) == null) {
                          return 'Enter a valid amount';
                        }
                        return null;
                      },
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 32),
              ElevatedButton(
                onPressed: _isSubmitting ? null : _submit,
                child: _isSubmitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Submit Invoice'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

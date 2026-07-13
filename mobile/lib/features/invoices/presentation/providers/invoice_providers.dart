import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/errors/failures.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/network/network_info.dart';
import '../../../../core/storage/pending_operations_queue.dart';
import '../../data/datasources/invoice_remote_datasource.dart';
import '../../data/repositories/invoice_repository_impl.dart';
import '../../domain/entities/invoice.dart';
import '../../domain/repositories/invoice_repository.dart';
import '../../domain/usecases/invoice_usecases.dart';

final invoiceRemoteDataSourceProvider =
    Provider<InvoiceRemoteDataSource>((ref) {
  return InvoiceRemoteDataSourceImpl(ref.watch(apiClientProvider));
});

final invoiceRepositoryProvider = Provider<InvoiceRepository>((ref) {
  return InvoiceRepositoryImpl(
    ref.watch(invoiceRemoteDataSourceProvider),
    ref.watch(networkInfoProvider),
    ref.watch(pendingOperationsQueueProvider),
  );
});

final getInvoicesProvider = Provider<GetInvoices>((ref) {
  return GetInvoices(ref.watch(invoiceRepositoryProvider));
});

final submitInvoiceProvider = Provider<SubmitInvoice>((ref) {
  return SubmitInvoice(ref.watch(invoiceRepositoryProvider));
});

final getPaymentsProvider = Provider<GetPayments>((ref) {
  return GetPayments(ref.watch(invoiceRepositoryProvider));
});

final invoicesProvider = FutureProvider<List<Invoice>>((ref) async {
  final result = await ref.watch(getInvoicesProvider).call();
  return result.fold(
    (f) => throw Exception(f.message),
    (invoices) => invoices,
  );
});

final paymentsProvider = FutureProvider<List<Payment>>((ref) async {
  final result = await ref.watch(getPaymentsProvider).call();
  return result.fold(
    (f) => throw Exception(f.message),
    (payments) => payments,
  );
});

// ---------------------------------------------------------------------------
// Invoice submission state
// ---------------------------------------------------------------------------

sealed class InvoiceSubmissionState {
  const InvoiceSubmissionState();
}

class InvoiceSubmissionInitial extends InvoiceSubmissionState {
  const InvoiceSubmissionInitial();
}

class InvoiceSubmissionLoading extends InvoiceSubmissionState {
  const InvoiceSubmissionLoading();
}

class InvoiceSubmissionSuccess extends InvoiceSubmissionState {
  final Invoice invoice;
  const InvoiceSubmissionSuccess(this.invoice);
}

class InvoiceSubmissionError extends InvoiceSubmissionState {
  final String message;
  final Map<String, List<String>> fieldErrors;
  const InvoiceSubmissionError(this.message, {this.fieldErrors = const {}});
}

class InvoiceSubmissionNotifier extends StateNotifier<InvoiceSubmissionState> {
  final SubmitInvoice _submitInvoice;
  final Ref _ref;

  InvoiceSubmissionNotifier(this._submitInvoice, this._ref)
      : super(const InvoiceSubmissionInitial());

  Future<void> submit({
    required String invoiceNumber,
    required double totalAmount,
    required String currency,
    String? purchaseOrderId,
    String? contractId,
    required List<Map<String, dynamic>> items,
    DateTime? invoiceDate,
    String? notes,
  }) async {
    state = const InvoiceSubmissionLoading();
    final result = await _submitInvoice(
      invoiceNumber: invoiceNumber,
      totalAmount: totalAmount,
      currency: currency,
      purchaseOrderId: purchaseOrderId,
      contractId: contractId,
      items: items,
      invoiceDate: invoiceDate,
      notes: notes,
    );
    result.fold(
      (failure) {
        if (failure is ValidationFailure) {
          state = InvoiceSubmissionError(
            failure.message,
            fieldErrors: failure.errors,
          );
        } else {
          state = InvoiceSubmissionError(failure.message);
        }
      },
      (invoice) {
        state = InvoiceSubmissionSuccess(invoice);
        _ref.invalidate(invoicesProvider);
      },
    );
  }

  void reset() => state = const InvoiceSubmissionInitial();
}

final invoiceSubmissionNotifierProvider = StateNotifierProvider.autoDispose<
    InvoiceSubmissionNotifier, InvoiceSubmissionState>((ref) {
  return InvoiceSubmissionNotifier(ref.watch(submitInvoiceProvider), ref);
});

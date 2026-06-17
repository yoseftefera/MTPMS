import 'package:intl/intl.dart';

/// Utility helpers for monetary value formatting.
class CurrencyFormatter {
  CurrencyFormatter._();

  /// Formats [amount] with the given [currencyCode] (e.g., `USD 1,234.56`).
  static String format(double amount, {String currencyCode = 'USD'}) {
    final formatter = NumberFormat.currency(
      symbol: '$currencyCode ',
      decimalDigits: 2,
    );
    return formatter.format(amount);
  }

  /// Parses a monetary string to [double], returning `0.0` on failure.
  static double parse(String value) {
    return double.tryParse(value.replaceAll(RegExp(r'[^0-9.]'), '')) ?? 0.0;
  }
}

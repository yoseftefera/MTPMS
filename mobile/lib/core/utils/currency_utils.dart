import 'package:intl/intl.dart';

/// Currency formatting utilities.
/// All monetary values from the API are strings with exactly 2 decimal places.
class CurrencyUtils {
  CurrencyUtils._();

  /// Formats a numeric [amount] with the given [currencyCode].
  /// Example: formatAmount(1234.5, 'USD') → "USD 1,234.50"
  static String formatAmount(double amount, String currencyCode) {
    final formatter = NumberFormat.currency(
      symbol: '$currencyCode ',
      decimalDigits: 2,
    );
    return formatter.format(amount);
  }

  /// Parses a monetary string (e.g. "1234.50") to [double].
  static double parseAmount(String value) => double.parse(value);
}

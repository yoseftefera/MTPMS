import 'package:intl/intl.dart';

/// Utility class for consistent date/time formatting across the app.
class DateFormatter {
  DateFormatter._();

  static final _dateFormat = DateFormat('dd MMM yyyy');
  static final _dateTimeFormat = DateFormat('dd MMM yyyy, HH:mm');
  static final _timeFormat = DateFormat('HH:mm');
  static final _isoFormat = DateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'");

  static String formatDate(DateTime date) => _dateFormat.format(date);

  static String formatDateTime(DateTime dateTime) =>
      _dateTimeFormat.format(dateTime.toLocal());

  static String formatTime(DateTime dateTime) =>
      _timeFormat.format(dateTime.toLocal());

  static String toIso8601(DateTime dateTime) =>
      _isoFormat.format(dateTime.toUtc());

  static DateTime? parseIso8601(String? value) {
    if (value == null) return null;
    return DateTime.tryParse(value)?.toLocal();
  }

  /// Returns a human-readable relative time string (e.g., "2 hours ago").
  static String timeAgo(DateTime dateTime) {
    final now = DateTime.now();
    final diff = now.difference(dateTime.toLocal());

    if (diff.inSeconds < 60) return 'Just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    if (diff.inDays < 7) return '${diff.inDays}d ago';
    return formatDate(dateTime);
  }
}

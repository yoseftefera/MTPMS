import 'package:intl/intl.dart';

/// Date/time formatting utilities.
class AppDateUtils {
  AppDateUtils._();

  static final DateFormat _displayDate = DateFormat('MMM d, yyyy');
  static final DateFormat _displayDateTime = DateFormat('MMM d, yyyy HH:mm');

  /// Formats a [DateTime] as a human-readable date string (e.g. "Jan 5, 2025").
  static String formatDate(DateTime date) => _displayDate.format(date);

  /// Formats a [DateTime] as a human-readable date-time string.
  static String formatDateTime(DateTime date) =>
      _displayDateTime.format(date.toLocal());

  /// Parses an ISO 8601 string to [DateTime].
  static DateTime parseIso8601(String value) => DateTime.parse(value).toLocal();

  /// Returns `true` if [date] is in the past.
  static bool isPast(DateTime date) => date.isBefore(DateTime.now());

  /// Returns a relative time string (e.g. "2 hours ago", "in 3 days").
  static String timeAgo(DateTime date) {
    final now = DateTime.now();
    final diff = now.difference(date);

    if (diff.isNegative) {
      final future = date.difference(now);
      if (future.inDays > 0)
        return 'in ${future.inDays} day${future.inDays == 1 ? '' : 's'}';
      if (future.inHours > 0)
        return 'in ${future.inHours} hour${future.inHours == 1 ? '' : 's'}';
      return 'in ${future.inMinutes} minute${future.inMinutes == 1 ? '' : 's'}';
    }

    if (diff.inDays > 0)
      return '${diff.inDays} day${diff.inDays == 1 ? '' : 's'} ago';
    if (diff.inHours > 0)
      return '${diff.inHours} hour${diff.inHours == 1 ? '' : 's'} ago';
    if (diff.inMinutes > 0)
      return '${diff.inMinutes} minute${diff.inMinutes == 1 ? '' : 's'} ago';
    return 'just now';
  }
}

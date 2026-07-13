import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../../core/widgets/error_view.dart';
import '../../../../core/widgets/loading_indicator.dart';
import '../../../../core/widgets/offline_banner_scaffold.dart';
import '../../domain/entities/app_notification.dart';
import '../providers/notification_providers.dart';

/// Screen showing all in-app notifications with read/unread status.
class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notificationsAsync = ref.watch(notificationsNotifierProvider);

    return OfflineBannerScaffold(
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Notifications'),
          actions: [
            TextButton(
              onPressed: () => ref
                  .read(notificationsNotifierProvider.notifier)
                  .markAllAsRead(),
              child: const Text('Mark all read'),
            ),
          ],
        ),
        body: RefreshIndicator(
          onRefresh: () =>
              ref.read(notificationsNotifierProvider.notifier).refresh(),
          child: notificationsAsync.when(
            data: (notifications) {
              if (notifications.isEmpty) {
                return const _EmptyNotifications();
              }
              return ListView.separated(
                itemCount: notifications.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (ctx, i) =>
                    _NotificationTile(notification: notifications[i]),
              );
            },
            loading: () => const LoadingIndicator(),
            error: (err, _) => ErrorView(
              message: err.toString(),
              onRetry: () =>
                  ref.read(notificationsNotifierProvider.notifier).refresh(),
            ),
          ),
        ),
      ),
    );
  }
}

class _EmptyNotifications extends StatelessWidget {
  const _EmptyNotifications();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.notifications_none_rounded,
              size: 64, color: Theme.of(context).colorScheme.onSurfaceVariant),
          const SizedBox(height: 16),
          Text('All caught up!',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Text(
            'No notifications yet.',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
          ),
        ],
      ),
    );
  }
}

class _NotificationTile extends ConsumerWidget {
  final AppNotification notification;

  const _NotificationTile({required this.notification});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final timeAgo = _formatRelativeTime(notification.createdAt);

    return InkWell(
      onTap: () {
        if (!notification.isRead) {
          ref
              .read(notificationsNotifierProvider.notifier)
              .markAsRead(notification.id);
        }
      },
      child: Container(
        color: notification.isRead
            ? null
            : Theme.of(context).colorScheme.primaryContainer.withAlpha(77),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Event icon
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: _eventColor(notification.eventType).withAlpha(25),
                shape: BoxShape.circle,
              ),
              child: Icon(
                _eventIcon(notification.eventType),
                size: 20,
                color: _eventColor(notification.eventType),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          notification.title,
                          style:
                              Theme.of(context).textTheme.titleSmall?.copyWith(
                                    fontWeight: notification.isRead
                                        ? FontWeight.normal
                                        : FontWeight.w600,
                                  ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      Text(
                        timeAgo,
                        style: Theme.of(context).textTheme.labelSmall?.copyWith(
                              color: Theme.of(context)
                                  .colorScheme
                                  .onSurfaceVariant,
                            ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    notification.body,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            if (!notification.isRead) ...[
              const SizedBox(width: 8),
              Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: Theme.of(context).colorScheme.primary,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  IconData _eventIcon(String eventType) {
    return switch (eventType) {
      'tender_published' => Icons.gavel_rounded,
      'purchase_order_issued' => Icons.shopping_cart_outlined,
      'purchase_order_accepted' ||
      'purchase_order_rejected' =>
        Icons.shopping_cart_checkout_outlined,
      'invoice_submitted' ||
      'invoice_status_changed' =>
        Icons.receipt_long_outlined,
      'payment_processed' => Icons.payments_outlined,
      'bid_evaluation_completed' => Icons.leaderboard_outlined,
      'contract_renewal_alert' => Icons.article_outlined,
      'account_locked' => Icons.lock_outline_rounded,
      _ => Icons.notifications_outlined,
    };
  }

  Color _eventColor(String eventType) {
    return switch (eventType) {
      'tender_published' => Colors.blue,
      'purchase_order_issued' => Colors.green,
      'purchase_order_rejected' => Colors.red,
      'invoice_status_changed' => Colors.orange,
      'payment_processed' => Colors.teal,
      'account_locked' => Colors.red,
      _ => Colors.grey,
    };
  }

  String _formatRelativeTime(DateTime dt) {
    final now = DateTime.now();
    final diff = now.difference(dt);

    if (diff.inMinutes < 1) return 'Just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    if (diff.inDays < 7) return '${diff.inDays}d ago';
    return DateFormat('dd MMM').format(dt);
  }
}

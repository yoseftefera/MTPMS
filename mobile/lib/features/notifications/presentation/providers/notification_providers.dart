import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../data/datasources/notification_remote_datasource.dart';
import '../../data/repositories/notification_repository_impl.dart';
import '../../domain/entities/app_notification.dart';
import '../../domain/repositories/notification_repository.dart';
import '../../domain/usecases/notification_usecases.dart';

final notificationRemoteDataSourceProvider =
    Provider<NotificationRemoteDataSource>((ref) {
  return NotificationRemoteDataSourceImpl(ref.watch(apiClientProvider));
});

final notificationRepositoryProvider = Provider<NotificationRepository>((ref) {
  return NotificationRepositoryImpl(
      ref.watch(notificationRemoteDataSourceProvider));
});

final getNotificationsProvider = Provider<GetNotifications>((ref) {
  return GetNotifications(ref.watch(notificationRepositoryProvider));
});

final notificationsListProvider =
    FutureProvider<List<AppNotification>>((ref) async {
  final result = await ref.watch(getNotificationsProvider).call();
  return result.fold(
    (f) => throw Exception(f.message),
    (n) => n,
  );
});

final unreadCountProvider = FutureProvider<int>((ref) async {
  final repo = ref.watch(notificationRepositoryProvider);
  final result = await repo.getUnreadCount();
  return result.fold((f) => 0, (count) => count);
});

// ---------------------------------------------------------------------------
// Notifications state notifier (optimistic read marking)
// ---------------------------------------------------------------------------

class NotificationsNotifier
    extends StateNotifier<AsyncValue<List<AppNotification>>> {
  final NotificationRepository _repository;
  final Ref _ref;

  NotificationsNotifier(this._repository, this._ref)
      : super(const AsyncValue.loading()) {
    _load();
  }

  Future<void> _load() async {
    state = const AsyncValue.loading();
    final result = await _repository.getNotifications();
    result.fold(
      (failure) =>
          state = AsyncValue.error(failure.message, StackTrace.current),
      (notifications) => state = AsyncValue.data(notifications),
    );
  }

  Future<void> refresh() => _load();

  Future<void> markAsRead(String notificationId) async {
    // Optimistic update.
    final current = state.valueOrNull;
    if (current != null) {
      state = AsyncValue.data(
        current.map((n) {
          if (n.id == notificationId) return n.copyWith(isRead: true);
          return n;
        }).toList(),
      );
    }
    await _repository.markAsRead(notificationId);
    _ref.invalidate(unreadCountProvider);
  }

  Future<void> markAllAsRead() async {
    // Optimistic update.
    final current = state.valueOrNull;
    if (current != null) {
      state = AsyncValue.data(
        current.map((n) => n.copyWith(isRead: true)).toList(),
      );
    }
    await _repository.markAllAsRead();
    _ref.invalidate(unreadCountProvider);
  }
}

final notificationsNotifierProvider = StateNotifierProvider<
    NotificationsNotifier, AsyncValue<List<AppNotification>>>((ref) {
  return NotificationsNotifier(
    ref.watch(notificationRepositoryProvider),
    ref,
  );
});

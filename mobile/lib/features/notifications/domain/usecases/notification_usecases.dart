import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/app_notification.dart';
import '../repositories/notification_repository.dart';

class GetNotifications {
  final NotificationRepository _repository;

  const GetNotifications(this._repository);

  Future<Either<Failure, List<AppNotification>>> call({int page = 1}) =>
      _repository.getNotifications(page: page);
}

class MarkNotificationAsRead {
  final NotificationRepository _repository;

  const MarkNotificationAsRead(this._repository);

  Future<Either<Failure, void>> call(String id) => _repository.markAsRead(id);
}

class MarkAllNotificationsAsRead {
  final NotificationRepository _repository;

  const MarkAllNotificationsAsRead(this._repository);

  Future<Either<Failure, void>> call() => _repository.markAllAsRead();
}

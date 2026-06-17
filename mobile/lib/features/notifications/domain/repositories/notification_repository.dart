import 'package:dartz/dartz.dart';

import '../../../../core/errors/failures.dart';
import '../entities/app_notification.dart';

abstract class NotificationRepository {
  Future<Either<Failure, List<AppNotification>>> getNotifications({
    int page = 1,
    int perPage = 20,
  });

  Future<Either<Failure, int>> getUnreadCount();

  Future<Either<Failure, void>> markAsRead(String id);

  Future<Either<Failure, void>> markAllAsRead();
}

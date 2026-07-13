import '../../../../core/network/api_client.dart';
import '../models/notification_model.dart';

abstract class NotificationRemoteDataSource {
  Future<List<NotificationModel>> getNotifications({int page = 1});
  Future<void> markAsRead(String notificationId);
  Future<void> markAllAsRead();
  Future<int> getUnreadCount();
}

class NotificationRemoteDataSourceImpl implements NotificationRemoteDataSource {
  final ApiClient _client;

  const NotificationRemoteDataSourceImpl(this._client);

  @override
  Future<List<NotificationModel>> getNotifications({int page = 1}) async {
    final response = await _client.get(
      '/notifications',
      queryParameters: {'page': page, 'per_page': 20},
    );
    final data = (response as Map<String, dynamic>)['data'] as List<dynamic>;
    return data
        .map((e) => NotificationModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> markAsRead(String notificationId) async {
    await _client.post('/notifications/$notificationId/read');
  }

  @override
  Future<void> markAllAsRead() async {
    await _client.post('/notifications/read-all');
  }

  @override
  Future<int> getUnreadCount() async {
    final response = await _client.get('/notifications/unread-count');
    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return (data['unread_count'] as num?)?.toInt() ?? 0;
  }
}

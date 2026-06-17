import '../../../../core/network/api_client.dart';

abstract class NotificationRemoteDataSource {
  Future<Map<String, dynamic>> getNotifications({int page = 1});
  Future<void> markAsRead(String id);
  Future<void> markAllAsRead();
  Future<Map<String, dynamic>> getUnreadCount();
}

class NotificationRemoteDataSourceImpl implements NotificationRemoteDataSource {
  const NotificationRemoteDataSourceImpl(this._apiClient);

  final ApiClient _apiClient;

  @override
  Future<Map<String, dynamic>> getNotifications({int page = 1}) async {
    final data = await _apiClient.get(
      '/notifications',
      queryParameters: {'page': page},
    );
    return data as Map<String, dynamic>;
  }

  @override
  Future<void> markAsRead(String id) async {
    await _apiClient.patch('/notifications/$id/read');
  }

  @override
  Future<void> markAllAsRead() async {
    await _apiClient.patch('/notifications/read-all');
  }

  @override
  Future<Map<String, dynamic>> getUnreadCount() async {
    final data = await _apiClient.get('/notifications/unread-count');
    return data as Map<String, dynamic>;
  }
}

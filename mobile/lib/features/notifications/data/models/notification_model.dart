import '../../domain/entities/app_notification.dart';

class NotificationModel extends AppNotification {
  const NotificationModel({
    required super.id,
    required super.title,
    required super.body,
    required super.eventType,
    required super.isRead,
    super.actionUrl,
    required super.createdAt,
  });

  factory NotificationModel.fromJson(Map<String, dynamic> json) {
    return NotificationModel(
      id: json['id'] as String,
      title: json['title'] as String? ?? '',
      body: json['body'] as String? ?? json['message'] as String? ?? '',
      eventType: json['event_type'] as String? ?? 'general',
      isRead: json['is_read'] as bool? ?? false,
      actionUrl: json['action_url'] as String?,
      createdAt: DateTime.parse(
          json['created_at'] as String? ?? DateTime.now().toIso8601String()),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        'body': body,
        'event_type': eventType,
        'is_read': isRead,
        'action_url': actionUrl,
        'created_at': createdAt.toIso8601String(),
      };
}

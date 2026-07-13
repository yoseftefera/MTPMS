import 'package:equatable/equatable.dart';

/// Domain entity representing an in-app notification.
class AppNotification extends Equatable {
  final String id;
  final String title;
  final String body;
  final String eventType;
  final bool isRead;
  final String? actionUrl;
  final DateTime createdAt;

  const AppNotification({
    required this.id,
    required this.title,
    required this.body,
    required this.eventType,
    required this.isRead,
    this.actionUrl,
    required this.createdAt,
  });

  AppNotification copyWith({bool? isRead}) => AppNotification(
        id: id,
        title: title,
        body: body,
        eventType: eventType,
        isRead: isRead ?? this.isRead,
        actionUrl: actionUrl,
        createdAt: createdAt,
      );

  @override
  List<Object?> get props =>
      [id, title, body, eventType, isRead, actionUrl, createdAt];
}

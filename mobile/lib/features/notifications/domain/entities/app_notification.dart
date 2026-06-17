import 'package:equatable/equatable.dart';

/// Domain entity for an in-app notification.
class AppNotification extends Equatable {
  final String id;
  final String title;
  final String body;
  final String eventType;
  final bool isRead;
  final DateTime createdAt;
  final Map<String, dynamic>? data;

  const AppNotification({
    required this.id,
    required this.title,
    required this.body,
    required this.eventType,
    required this.isRead,
    required this.createdAt,
    this.data,
  });

  AppNotification copyWith({bool? isRead}) {
    return AppNotification(
      id: id,
      title: title,
      body: body,
      eventType: eventType,
      isRead: isRead ?? this.isRead,
      createdAt: createdAt,
      data: data,
    );
  }

  @override
  List<Object?> get props => [id, title, body, eventType, isRead, createdAt];
}

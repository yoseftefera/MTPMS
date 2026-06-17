import 'package:equatable/equatable.dart';

/// Domain entity representing an in-app notification.
class NotificationItem extends Equatable {
  const NotificationItem({
    required this.id,
    required this.title,
    required this.body,
    required this.eventType,
    required this.isRead,
    required this.createdAt,
    this.entityType,
    this.entityId,
  });

  final String id;
  final String title;
  final String body;
  final String eventType;
  final bool isRead;
  final DateTime createdAt;
  final String? entityType;
  final String? entityId;

  @override
  List<Object?> get props => [id, isRead, createdAt];
}

import 'package:equatable/equatable.dart';

/// Domain entity representing an in-app notification.
class AppNotification extends Equatable {
  final String id;
  final String tenantId;
  final String userId;
  final String eventType;
  final String title;
  final String body;
  final bool isRead;
  final Map<String, dynamic>? data;
  final DateTime createdAt;

  const AppNotification({
    required this.id,
    required this.tenantId,
    required this.userId,
    required this.eventType,
    required this.title,
    required this.body,
    required this.isRead,
    this.data,
    required this.createdAt,
  });

  @override
  List<Object?> get props => [id, tenantId, userId];
}

import 'package:equatable/equatable.dart';

/// Domain entity representing an authenticated supplier user.
class User extends Equatable {
  final String id;
  final String tenantId;
  final String name;
  final String email;
  final String role;
  final List<String> permissions;
  final String? avatar;
  final String? phone;

  const User({
    required this.id,
    required this.tenantId,
    required this.name,
    required this.email,
    required this.role,
    this.permissions = const [],
    this.avatar,
    this.phone,
  });

  @override
  List<Object?> get props =>
      [id, tenantId, name, email, role, permissions, avatar, phone];
}

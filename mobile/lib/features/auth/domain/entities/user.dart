import 'package:equatable/equatable.dart';

/// Domain entity representing an authenticated user.
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
    required this.permissions,
    this.avatar,
    this.phone,
  });

  @override
  List<Object?> get props => [id, tenantId, email, role];
}

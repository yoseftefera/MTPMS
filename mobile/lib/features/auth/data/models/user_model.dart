import '../../domain/entities/user.dart';

/// Data model for deserializing the user object from the API response.
class UserModel extends User {
  const UserModel({
    required super.id,
    required super.tenantId,
    required super.name,
    required super.email,
    required super.role,
    required super.permissions,
    super.avatar,
    super.phone,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) {
    return UserModel(
      id: json['user_id'] as String,
      tenantId: json['tenant_id'] as String,
      name: json['name'] as String,
      email: json['email'] as String,
      role: json['role'] as String,
      permissions: List<String>.from(json['permissions'] as List? ?? []),
      avatar: json['avatar'] as String?,
      phone: json['phone'] as String?,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'user_id': id,
      'tenant_id': tenantId,
      'name': name,
      'email': email,
      'role': role,
      'permissions': permissions,
      'avatar': avatar,
      'phone': phone,
    };
  }

  factory UserModel.fromEntity(User user) {
    return UserModel(
      id: user.id,
      tenantId: user.tenantId,
      name: user.name,
      email: user.email,
      role: user.role,
      permissions: user.permissions,
      avatar: user.avatar,
      phone: user.phone,
    );
  }
}

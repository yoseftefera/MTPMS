import '../../domain/entities/user.dart';

/// Data model that maps JSON from the API to the [User] domain entity.
class UserModel extends User {
  const UserModel({
    required super.id,
    required super.tenantId,
    required super.name,
    required super.email,
    required super.role,
    super.permissions = const [],
    super.avatar,
    super.phone,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) {
    // The API may return the id as 'user_id' (JWT payload) or 'id' (resource response).
    final id = (json['user_id'] ?? json['id']) as String;
    final rawPerms = json['permissions'];
    final permissions = rawPerms is List
        ? rawPerms.map((e) => e.toString()).toList()
        : <String>[];

    return UserModel(
      id: id,
      tenantId: json['tenant_id'] as String,
      name: json['name'] as String,
      email: json['email'] as String,
      role: json['role'] as String? ?? 'supplier',
      permissions: permissions,
      avatar: json['avatar'] as String?,
      phone: json['phone'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
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

import '../../../../core/network/api_client.dart';

/// Remote data source for dashboard KPI data.
abstract class DashboardRemoteDataSource {
  Future<Map<String, dynamic>> getDashboardSummary();
}

class DashboardRemoteDataSourceImpl implements DashboardRemoteDataSource {
  const DashboardRemoteDataSourceImpl(this._apiClient);

  final ApiClient _apiClient;

  @override
  Future<Map<String, dynamic>> getDashboardSummary() async {
    final data = await _apiClient.get('/supplier/dashboard');
    return data as Map<String, dynamic>;
  }
}

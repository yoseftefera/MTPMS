import '../../../../core/network/api_client.dart';
import '../models/dashboard_summary_model.dart';

abstract class DashboardRemoteDataSource {
  Future<DashboardSummaryModel> getDashboardSummary();
}

class DashboardRemoteDataSourceImpl implements DashboardRemoteDataSource {
  final ApiClient _client;

  const DashboardRemoteDataSourceImpl(this._client);

  @override
  Future<DashboardSummaryModel> getDashboardSummary() async {
    final response = await _client.get('/supplier/dashboard');
    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return DashboardSummaryModel.fromJson(data);
  }
}

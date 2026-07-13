import 'package:dio/dio.dart';

import '../../../../core/network/api_client.dart';
import '../models/bid_model.dart';

abstract class BidRemoteDataSource {
  Future<BidModel> submitBid({
    required String tenderId,
    required double totalAmount,
    required int deliveryDays,
    String? technicalNotes,
    List<String>? documentPaths,
  });
}

class BidRemoteDataSourceImpl implements BidRemoteDataSource {
  final ApiClient _client;

  const BidRemoteDataSourceImpl(this._client);

  @override
  Future<BidModel> submitBid({
    required String tenderId,
    required double totalAmount,
    required int deliveryDays,
    String? technicalNotes,
    List<String>? documentPaths,
  }) async {
    // Build multipart form if documents are attached.
    if (documentPaths != null && documentPaths.isNotEmpty) {
      final formData = FormData.fromMap({
        'total_amount': totalAmount.toString(),
        'delivery_days': deliveryDays,
        if (technicalNotes != null) 'technical_notes': technicalNotes,
        'documents': await Future.wait(
          documentPaths.map(
            (path) async => await MultipartFile.fromFile(
              path,
              filename: path.split('/').last,
            ),
          ),
        ),
      });

      final response = await _client.post(
        '/tenders/$tenderId/bids',
        data: formData,
        options: Options(
          contentType: 'multipart/form-data',
        ),
      );
      final data =
          (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
      return BidModel.fromJson(data);
    }

    final response = await _client.post(
      '/tenders/$tenderId/bids',
      data: {
        'total_amount': totalAmount.toString(),
        'delivery_days': deliveryDays,
        if (technicalNotes != null) 'technical_notes': technicalNotes,
      },
    );
    final data =
        (response as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return BidModel.fromJson(data);
  }
}

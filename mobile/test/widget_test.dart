import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pmp_mobile/core/constants/app_constants.dart';

void main() {
  testWidgets('App constants are correctly defined', (WidgetTester tester) async {
    // Verify core constants are set.
    expect(AppConstants.appName, equals('PMP Supplier Portal'));
    expect(AppConstants.listCacheTtl, equals(const Duration(hours: 24)));
    expect(AppConstants.detailCacheTtl, equals(const Duration(hours: 1)));
    expect(AppConstants.hiveBoxTenders, isNotEmpty);
    expect(AppConstants.hiveBoxPurchaseOrders, isNotEmpty);
    expect(AppConstants.hiveBoxInvoices, isNotEmpty);
    expect(AppConstants.hiveBoxDashboard, isNotEmpty);
    expect(AppConstants.hiveBoxNotifications, isNotEmpty);
    expect(AppConstants.hiveBoxOfflineQueue, isNotEmpty);
  });

  testWidgets('ProviderScope renders without error', (WidgetTester tester) async {
    await tester.pumpWidget(
      const ProviderScope(
        child: MaterialApp(
          home: Scaffold(
            body: Center(child: Text('PMP Supplier Portal')),
          ),
        ),
      ),
    );
    expect(find.text('PMP Supplier Portal'), findsOneWidget);
  });
}

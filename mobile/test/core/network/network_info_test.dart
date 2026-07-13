import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:pmp_mobile/core/network/network_info.dart';

class MockConnectivity extends Mock implements Connectivity {}

void main() {
  late MockConnectivity mockConnectivity;
  late NetworkInfoImpl networkInfo;

  setUp(() {
    mockConnectivity = MockConnectivity();
    networkInfo = NetworkInfoImpl(mockConnectivity);
  });

  group('NetworkInfoImpl.isConnected', () {
    test('returns true when connected via WiFi', () async {
      when(() => mockConnectivity.checkConnectivity())
          .thenAnswer((_) async => ConnectivityResult.wifi);

      expect(await networkInfo.isConnected, isTrue);
    });

    test('returns true when connected via mobile data', () async {
      when(() => mockConnectivity.checkConnectivity())
          .thenAnswer((_) async => ConnectivityResult.mobile);

      expect(await networkInfo.isConnected, isTrue);
    });

    test('returns true when connected via ethernet', () async {
      when(() => mockConnectivity.checkConnectivity())
          .thenAnswer((_) async => ConnectivityResult.ethernet);

      expect(await networkInfo.isConnected, isTrue);
    });

    test('returns false when there is no connection', () async {
      when(() => mockConnectivity.checkConnectivity())
          .thenAnswer((_) async => ConnectivityResult.none);

      expect(await networkInfo.isConnected, isFalse);
    });
  });

  group('NetworkInfoImpl.onConnectivityChanged', () {
    test('emits true when WiFi connection event arrives', () async {
      final controller =
          Stream<ConnectivityResult>.fromIterable([ConnectivityResult.wifi]);
      when(() => mockConnectivity.onConnectivityChanged).thenAnswer(
        (_) => controller,
      );

      expect(networkInfo.onConnectivityChanged, emitsInOrder([true]));
    });

    test('emits false when none connection event arrives', () async {
      final controller =
          Stream<ConnectivityResult>.fromIterable([ConnectivityResult.none]);
      when(() => mockConnectivity.onConnectivityChanged).thenAnswer(
        (_) => controller,
      );

      expect(networkInfo.onConnectivityChanged, emitsInOrder([false]));
    });

    test('emits correct boolean sequence for connect then disconnect', () {
      final events = [
        ConnectivityResult.wifi,
        ConnectivityResult.none,
        ConnectivityResult.mobile,
      ];
      when(() => mockConnectivity.onConnectivityChanged).thenAnswer(
        (_) => Stream.fromIterable(events),
      );

      expect(
        networkInfo.onConnectivityChanged,
        emitsInOrder([true, false, true]),
      );
    });
  });
}

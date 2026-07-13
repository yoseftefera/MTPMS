import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/constants/app_constants.dart';
import 'core/network/sync_service.dart';
import 'core/router/app_router.dart';
import 'core/storage/hive_service.dart';
import 'core/theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Initialize Hive for offline caching (opens all boxes).
  await HiveService.initialize();

  runApp(
    const ProviderScope(
      child: PmpApp(),
    ),
  );
}

class PmpApp extends ConsumerStatefulWidget {
  const PmpApp({super.key});

  @override
  ConsumerState<PmpApp> createState() => _PmpAppState();
}

class _PmpAppState extends ConsumerState<PmpApp> {
  @override
  void initState() {
    super.initState();
    // Start the sync service so queued writes are replayed on reconnect.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(syncServiceProvider).startListening();
    });
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(appRouterProvider);

    return MaterialApp.router(
      title: AppConstants.appName,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      darkTheme: AppTheme.darkTheme,
      themeMode: ThemeMode.system,
      routerConfig: router,
    );
  }
}

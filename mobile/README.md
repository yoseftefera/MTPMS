# PMP Mobile — Supplier Portal

Flutter mobile application for the Procurement Management Platform (PMP) Supplier Portal.

## Architecture

Clean Architecture with three layers per feature:

```
lib/
├── core/                          # Shared infrastructure
│   ├── constants/                 # App-wide constants & config
│   ├── errors/                    # Failures & exceptions
│   ├── network/                   # Dio ApiClient + interceptors
│   │   └── interceptors/
│   │       ├── auth_interceptor.dart     # JWT Bearer + refresh
│   │       └── tenant_interceptor.dart   # X-Tenant-ID header
│   ├── router/                    # GoRouter configuration
│   ├── storage/                   # Hive offline cache service
│   ├── theme/                     # Material 3 light/dark themes
│   └── utils/                     # Date formatting, Either helpers
│
└── features/
    ├── auth/                      # Login, JWT management
    ├── dashboard/                 # Supplier dashboard
    ├── tenders/                   # Open tender list
    ├── bids/                      # Bid submission
    ├── purchase_orders/           # PO list (accept/reject)
    ├── invoices/                  # Invoice submission & tracking
    └── notifications/             # In-app notifications
```

Each feature follows:
```
feature/
├── data/
│   ├── datasources/   # Remote API calls (Dio)
│   ├── models/        # JSON serialization
│   └── repositories/  # Implements domain contracts + caching
├── domain/
│   ├── entities/      # Pure Dart business objects
│   ├── repositories/  # Abstract contracts
│   └── usecases/      # Single-responsibility use cases
└── presentation/
    ├── providers/     # Riverpod state notifiers
    ├── screens/       # Flutter screens
    └── widgets/       # Reusable UI components
```

## State Management

[Riverpod](https://riverpod.dev/) with `StateNotifierProvider` for mutable state and `FutureProvider.family` for async data fetching.

## Offline Support

- **Hive** boxes cache API responses locally.
- List data: 24-hour TTL (`AppConstants.listCacheTtl`)
- Detail data: 1-hour TTL (`AppConstants.detailCacheTtl`)
- **Connectivity Plus** detects network state and shows an offline banner.
- Write operations (bid submission, invoice upload) are queued in `PendingOperationsQueue` and synced on reconnect.

## HTTP Client

Dio with two interceptors:
- `AuthInterceptor` — attaches `Authorization: Bearer <token>` and handles 401 token refresh.
- `TenantInterceptor` — attaches `X-Tenant-ID` header for multi-tenant resolution.

## Getting Started

```bash
# Install dependencies
flutter pub get

# Run code generation (Hive adapters, Riverpod generators)
dart run build_runner build --delete-conflicting-outputs

# Run the app
flutter run

# Run tests
flutter test
```

## Requirements

- Flutter 3.10+
- Dart 3.0+
- Backend: Laravel 12 API at `/api/v1`

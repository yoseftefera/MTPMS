// This file is intentionally minimal.
// All ApiClient, AuthInterceptor, and TenantInterceptor providers are
// defined in api_client.dart (authInterceptorProvider, tenantInterceptorProvider,
// apiClientProvider, dioProvider).
//
// Re-export them here for backwards-compatible imports.
export 'api_client.dart'
    show
        apiClientProvider,
        dioProvider,
        authInterceptorProvider,
        tenantInterceptorProvider;

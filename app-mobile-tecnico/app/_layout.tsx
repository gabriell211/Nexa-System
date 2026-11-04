import React, { useEffect } from 'react';
import { ActivityIndicator, AppState, StatusBar, View } from 'react-native';
import { Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { QueryClient, QueryClientProvider, focusManager } from '@tanstack/react-query';
import { AuthProvider, useAuth } from '../src/auth';
import { BrandIdentity } from '../src/brand/BrandIdentity';
import { color } from '../src/brand/theme';

const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 20_000, retry: 1, refetchOnWindowFocus: true } },
});

function Navigation(): React.JSX.Element {
  const { user, restoring } = useAuth();
  if (restoring) {
    return <View style={{ flex: 1, backgroundColor: color.bg, justifyContent: 'center',
      alignItems: 'center', gap: 25 }}>
      <BrandIdentity />
      <ActivityIndicator size="large" color={color.brand} accessibilityLabel="Carregando sessão" />
    </View>;
  }
  return <Stack screenOptions={{
      headerShown: false, contentStyle: { backgroundColor: color.bg }, animation: 'slide_from_right',
    }}>
    <Stack.Protected guard={!user}>
      <Stack.Screen name="login" />
    </Stack.Protected>
    <Stack.Protected guard={!!user}>
      <Stack.Screen name="(secure)" />
    </Stack.Protected>
  </Stack>;
}

export default function RootLayout(): React.JSX.Element {
  useEffect(() => {
    const subscription = AppState.addEventListener('change', status => {
      focusManager.setFocused(status === 'active');
    });
    return () => subscription.remove();
  }, []);
  return <SafeAreaProvider>
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <StatusBar barStyle="dark-content" backgroundColor={color.bg} />
        <Navigation />
      </AuthProvider>
    </QueryClientProvider>
  </SafeAreaProvider>;
}

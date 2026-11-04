import React from 'react';
import { Stack } from 'expo-router';
import { color } from '../../src/brand/theme';

export default function PrivateLayout(): React.JSX.Element {
  return <Stack screenOptions={{
    headerShown: true,
    headerTintColor: color.navy,
    headerStyle: { backgroundColor: color.bg },
    headerShadowVisible: false,
    headerBackTitle: 'Voltar',
    contentStyle: { backgroundColor: color.bg },
    animation: 'slide_from_right',
  }}>
    <Stack.Screen name="index" options={{ title: 'Meus chamados', headerShown: false }} />
  </Stack>;
}

import { Router } from 'expo-router';
import { useEffect } from 'react';
import { useAuth } from '@/hooks/useAuth';
import { StatusBar } from 'expo-status-bar';

export default function RootLayout() {
  const { isAuthenticated, isLoading, initialize } = useAuth();

  useEffect(() => {
    initialize();
  }, [initialize]);

  if (isLoading) {
    return (
      <>
        <StatusBar style="auto" />
        <Root />
      </>
    );
  }

  return <Root />;
}

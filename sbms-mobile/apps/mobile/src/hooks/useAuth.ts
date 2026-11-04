import { useAuthStore } from '@/store/auth';

export function useAuth() {
  const store = useAuthStore();
  return {
    user: store.user,
    accessToken: store.accessToken,
    isAuthenticated: store.isAuthenticated,
    isLoading: store.isLoading,
    setAuth: store.setAuth,
    logout: store.logout,
    initialize: store.initialize,
  };
}

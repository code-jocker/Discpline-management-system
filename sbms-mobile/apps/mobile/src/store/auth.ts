import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { storage } from '../services/storage';
import { User, AuthState } from '../types';

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      accessToken: null,
      refreshToken: null,
      isAuthenticated: false,
      isLoading: true,

      setAuth: (user: User, accessToken: string, refreshToken: string) => {
        storage.setUser(user);
        storage.setAccessToken(accessToken);
        storage.setRefreshToken(refreshToken);
        set({ user, accessToken, refreshToken, isAuthenticated: true, isLoading: false });
      },

      logout: async () => {
        await storage.clearAll();
        set({ user: null, accessToken: null, refreshToken: null, isAuthenticated: false, isLoading: false });
      },

      initialize: async () => {
        const user = await storage.getUser();
        const accessToken = await storage.getAccessToken();
        const refreshToken = await storage.getRefreshToken();

        set({
          user,
          accessToken,
          refreshToken,
          isAuthenticated: Boolean(user && accessToken),
          isLoading: false,
        });
      },
    }),
    {
      name: 'sbms-auth',
      storage: createJSONStorage(() => AsyncStorage),
    }
  )
);

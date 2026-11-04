import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { User } from './types';

const ACCESS_TOKEN_KEY = 'sbms_access_token';
const REFRESH_TOKEN_KEY = 'sbms_refresh_token';
const USER_KEY = 'sbms_user';

export const storage = {
  async setAccessToken(token: string) {
    await SecureStore.setItemAsync(ACCESS_TOKEN_KEY, token);
  },
  async getAccessToken(): Promise<string | null> {
    return SecureStore.getItemAsync(ACCESS_TOKEN_KEY);
  },
  async deleteAccessToken() {
    await SecureStore.deleteItemAsync(ACCESS_TOKEN_KEY);
  },
  async setRefreshToken(token: string) {
    await SecureStore.setItemAsync(REFRESH_TOKEN_KEY, token);
  },
  async getRefreshToken(): Promise<string | null> {
    return SecureStore.getItemAsync(REFRESH_TOKEN_KEY);
  },
  async deleteRefreshToken() {
    await SecureStore.deleteItemAsync(REFRESH_TOKEN_KEY);
  },
  async setUser(user: User) {
    await AsyncStorage.setItem(USER_KEY, JSON.stringify(user));
  },
  async getUser(): Promise<User | null> {
    const raw = await AsyncStorage.getItem(USER_KEY);
    return raw ? (JSON.parse(raw) as User) : null;
  },
  async deleteUser() {
    await AsyncStorage.removeItem(USER_KEY);
  },
  async clearAll() {
    await this.deleteAccessToken();
    await this.deleteRefreshToken();
    await this.deleteUser();
  },
};

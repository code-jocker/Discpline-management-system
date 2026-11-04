import { StackScreenProps } from '@react-navigation/stack';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button, TextInput, Text, View, StyleSheet, TouchableOpacity, ActivityIndicator } from 'react-native';
import { useAuth } from '@/hooks/useAuth';
import { useRouter } from 'expo-router';

const schema = z.object({
  login: z.string().min(1, 'Email or username required'),
  password: z.string().min(1, 'Password required'),
});

export type LoginScreenProps = StackScreenProps<'login'>;

export default function LoginScreen() {
  const { setAuth, isAuthenticated } = useAuth();
  const router = useRouter();

  if (isAuthenticated) {
    router.replace('/(app)/dashboard');
    return null;
  }

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema) });

  const onSubmit = async (data: z.infer<typeof schema>) => {
    try {
      const res = await fetch(`${process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:4000'}/api/v1/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.error?.message || 'Login failed');
      setAuth(json.data.user, json.data.accessToken, json.data.refreshToken);
      router.replace('/(app)/dashboard');
    } catch (e: any) {
      alert(e.message || 'Login failed');
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Welcome Back</Text>
      <Text style={styles.subtitle}>Sign in to SBMS</Text>

      <View style={styles.card}>
        <TextInput
          style={styles.input}
          placeholder="Email or username"
          {...register('login')}
          autoCapitalize="none"
        />
        {errors.login && <Text style={styles.error}>{errors.login.message}</Text>}

        <TextInput
          style={styles.input}
          placeholder="Password"
          secureTextEntry
          {...register('password')}
        />
        {errors.password && <Text style={styles.error}>{errors.password.message}</Text>}

        <Button
          title={isSubmitting ? 'Signing in...' : 'Sign In'}
          onPress={handleSubmit(onSubmit)}
          disabled={isSubmitting}
        />
      </View>

      <TouchableOpacity style={styles.link} onPress={() => router.push('/(auth)/register')}>
        <Text style={styles.linkText}>Create account</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, justifyContent: 'center', padding: 24, backgroundColor: '#f8fafc' },
  title: { fontSize: 32, fontWeight: '800', color: '#0f172a', marginBottom: 8 },
  subtitle: { fontSize: 16, color: '#64748b', marginBottom: 32 },
  card: { gap: 16 },
  input: {
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 10,
    padding: 14,
    fontSize: 16,
    color: '#0f172a',
    backgroundColor: '#fff',
  },
  error: { color: '#dc2626', fontSize: 13, marginBottom: 4 },
  link: { marginTop: 20, alignItems: 'center' },
  linkText: { color: '#2563eb', fontSize: 15, fontWeight: '600' },
});

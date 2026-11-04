import { StackScreenProps } from '@react-navigation/stack';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button, TextInput, Text, View, StyleSheet, TouchableOpacity, ActivityIndicator } from 'react-native';
import { useRouter } from 'expo-router';

const schema = z.object({
  fullName: z.string().min(2, 'Full name required'),
  username: z.string().min(3, 'Username required'),
  email: z.string().email('Invalid email'),
  password: z.string().min(8, 'Password must be 8+ chars'),
  role: z.enum(['admin', 'headteacher', 'teacher', 'deputy_headteacher_discipline', 'it']),
});

export type RegisterScreenProps = StackScreenProps<'register'>;

export default function RegisterScreen() {
  const router = useRouter();

  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema) });

  const onSubmit = async (data: z.infer<typeof schema>) => {
    try {
      const res = await fetch(`${process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:4000'}/api/v1/auth/register`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.error?.message || 'Registration failed');
      alert('Account created. Please sign in.');
      router.replace('/(auth)/login');
    } catch (e: any) {
      alert(e.message || 'Registration failed');
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Create Account</Text>
      <Text style={styles.subtitle}>Join SBMS</Text>

      <View style={styles.card}>
        <TextInput style={styles.input} placeholder="Full name" {...register('fullName')} />
        {errors.fullName && <Text style={styles.error}>{errors.fullName.message}</Text>}

        <TextInput style={styles.input} placeholder="Username" {...register('username')} />
        {errors.username && <Text style={styles.error}>{errors.username.message}</Text>}

        <TextInput style={styles.input} placeholder="Email" {...register('email')} autoCapitalize="none" />
        {errors.email && <Text style={styles.error}>{errors.email.message}</Text>}

        <TextInput style={styles.input} placeholder="Password" secureTextEntry {...register('password')} />
        {errors.password && <Text style={styles.error}>{errors.password.message}</Text>}

        <Button title={isSubmitting ? 'Creating...' : 'Create Account'} onPress={handleSubmit(onSubmit)} disabled={isSubmitting} />
      </View>

      <TouchableOpacity style={styles.link} onPress={() => router.replace('/(auth)/login')}>
        <Text style={styles.linkText}>Already have an account? Sign in</Text>
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

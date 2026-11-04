import { View, Text, TextInput, Button, StyleSheet, ActivityIndicator, Picker, TouchableOpacity } from 'react-native';
import { useState } from 'react';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';
import { useRouter } from 'expo-router';

const REASONS = ['Late to class', 'Disruptive behavior', 'Missing assignment', 'Uniform violation', 'Other'];

export default function DeductScreen() {
  const { user } = useAuth();
  const router = useRouter();
  const [studentCode, setStudentCode] = useState('');
  const [reason, setReason] = useState(REASONS[0]);
  const [marks, setMarks] = useState(1);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');

  const handleSubmit = async () => {
    setLoading(true);
    setMessage('');
    try {
      // Resolve student by code first (simplified: server should accept student_code)
      const res = await fetch(`${process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:4000'}/api/v1/students?search=${encodeURIComponent(studentCode)}`);
      const json = await res.json();
      const students = json.data?.data ?? [];
      if (!students.length) throw new Error('Student not found');

      const student = students[0];

      const deductRes = await api.post('/deductions', {
        studentId: student.id,
        marksDeducted: parseInt(marks, 10),
        reason,
      });

      setMessage(`Deducted ${marks} mark(s) from ${student.fullName}. New risk: ${deductRes.data.data.risk.level}`);
      setStudentCode('');
      setMarks('1');
    } catch (e: any) {
      setMessage(e.message || 'Failed to log incident');
    } finally {
      setLoading(false);
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Quick Log Incident</Text>

      <View style={styles.card}>
        <TextInput
          style={styles.input}
          placeholder="Student code or name"
          value={studentCode}
          onChangeText={setStudentCode}
          autoCapitalize="characters"
        />

        <Picker
          selectedValue={reason}
          onValueChange={setReason}
          style={styles.picker}
        >
          {REASONS.map((r) => (
            <Picker.Item key={r} label={r} value={r} />
          ))}
        </Picker>

        <TextInput
          style={styles.input}
          placeholder="Marks to deduct"
          value={marks}
          onChangeText={setMarks}
          keyboardType="numeric"
        />

        <Button title={loading ? 'Logging...' : 'Log Incident'} onPress={handleSubmit} disabled={loading} />
      </View>

      {message && (
        <View style={[styles.message, { backgroundColor: message.startsWith('Failed') ? '#fee2e2' : '#dcfce7' }]}>
          <Text style={[styles.messageText, { color: message.startsWith('Failed') ? '#dc2626' : '#15803d' }]}>{message}</Text>
        </View>
      )}

      <TouchableOpacity style={styles.link} onPress={() => router.replace('/(app)/students')}>
        <Text style={styles.linkText}>View all students</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 20, backgroundColor: '#f8fafc' },
  title: { fontSize: 24, fontWeight: '800', color: '#0f172a', marginBottom: 24 },
  card: { backgroundColor: '#fff', borderRadius: 14, padding: 20, gap: 14 },
  input: {
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 10,
    padding: 14,
    fontSize: 16,
    color: '#0f172a',
    backgroundColor: '#fff',
  },
  picker: {
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 10,
    padding: 14,
    backgroundColor: '#fff',
    minHeight: 50,
  },
  message: { padding: 12, borderRadius: 10, marginTop: 12 },
  messageText: { fontSize: 14, fontWeight: '600' },
  link: { marginTop: 16, alignItems: 'center' },
  linkText: { color: '#2563eb', fontSize: 15, fontWeight: '600' },
});

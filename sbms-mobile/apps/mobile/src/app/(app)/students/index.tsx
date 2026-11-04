import { View, Text, FlatList, StyleSheet, Pressable, ActivityIndicator } from 'react-native';
import { useEffect, useState } from 'react';
import { Link } from 'expo-router';
import api from '@/services/api';
import { Student } from '@/types';

export default function StudentsScreen() {
  const [students, setStudents] = useState<Student[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/students?limit=100').then((r) => {
      setStudents(r.data.data.data ?? []);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  if (loading) return <ActivityIndicator style={styles.center} />;

  return (
    <View style={styles.container}>
      <FlatList
        data={students}
        keyExtractor={(item) => item.id}
        renderItem={({ item }) => (
          <Pressable style={styles.row} onPress={() => Link.href(`/students/${item.id}`)}>
            <Text style={styles.rowName}>{item.fullName}</Text>
            <Text style={styles.rowMeta}>{item.studentCode} · {item.className}</Text>
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f8fafc', padding: 16 },
  row: {
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 14,
    marginBottom: 10,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  rowName: { fontSize: 16, fontWeight: '700', color: '#0f172a' },
  rowMeta: { fontSize: 13, color: '#64748b', marginTop: 4 },
  center: { flex: 1, justifyContent: 'center' },
});

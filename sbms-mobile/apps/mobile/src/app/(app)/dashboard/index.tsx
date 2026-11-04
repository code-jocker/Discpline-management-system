import { View, Text, FlatList, StyleSheet, Pressable, ActivityIndicator } from 'react-native';
import { useEffect, useState } from 'react';
import { Link } from 'expo-router';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';
import { Student } from '@/types';

const RISK_COLORS: Record<string, string> = {
  Excellent: '#10b981',
  Warning: '#f59e0b',
  'Medium Risk': '#f97316',
  'High Risk': '#ef4444',
  Critical: '#7f1d1d',
};

export default function DashboardScreen() {
  const { user } = useAuth();
  const [students, setStudents] = useState<Student[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/students?limit=10').then((r) => {
      setStudents(r.data.data.data ?? []);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  if (loading) return <ActivityIndicator style={styles.center} />;

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.greeting}>Good day, {user?.fullName}</Text>
        <Text style={styles.role}>{user?.role}</Text>
      </View>

      <View style={styles.statRow}>
        <StatCard title="Total Students" value={students.length} color="#2563eb" />
        <StatCard title="Active" value={students.filter((s) => s.isActive).length} color="#10b981" />
      </View>

      <Text style={styles.sectionTitle}>Recent Students</Text>
      <FlatList
        data={students}
        keyExtractor={(item) => item.id}
        renderItem={({ item }) => (
          <Pressable style={styles.row} onPress={() => Link.href(`/students/${item.id}`)}>
            <Text style={styles.rowName}>{item.fullName}</Text>
            <Text style={styles.rowCode}>{item.studentCode}</Text>
            <Text style={[styles.rowBadge, { color: RISK_COLORS[item.className || 'Excellent'] || '#64748b' }]}>{item.className}</Text>
          </Pressable>
        )}
      />
    </View>
  );
}

function StatCard({ title, value, color }: { title: string; value: number; color: string }) {
  return (
    <View style={[styles.statCard, { borderLeftColor: color }]}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{title}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f8fafc', padding: 16 },
  header: { gap: 4 },
  greeting: { fontSize: 22, fontWeight: '700', color: '#0f172a' },
  role: { fontSize: 14, color: '#64748b' },
  statRow: { flexDirection: 'row', gap: 12, marginVertical: 16 },
  statCard: {
    flex: 1,
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 14,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderLeftWidth: 4,
  },
  statValue: { fontSize: 24, fontWeight: '800', color: '#0f172a' },
  statLabel: { fontSize: 13, color: '#64748b' },
  sectionTitle: { fontSize: 16, fontWeight: '700', color: '#0f172a', marginTop: 16 },
  row: {
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 12,
    marginBottom: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  rowName: { fontSize: 15, fontWeight: '600', color: '#0f172a' },
  rowCode: { fontSize: 13, color: '#64748b' },
  rowBadge: { fontSize: 12, fontWeight: '700', textTransform: 'uppercase' },
  center: { flex: 1, justifyContent: 'center' },
});

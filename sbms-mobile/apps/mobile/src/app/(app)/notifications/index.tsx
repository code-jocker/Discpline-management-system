import { View, Text, FlatList, StyleSheet, Pressable, ActivityIndicator } from 'react-native';
import { useEffect, useState } from 'react';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';
import { NotificationItem } from '@/types';

export default function NotificationsScreen() {
  const { user } = useAuth();
  const [items, setItems] = useState<NotificationItem[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/notifications?limit=50').then((r) => {
      setItems(r.data.data.data ?? []);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, [user?.id]);

  if (loading) return <ActivityIndicator style={styles.center} />;

  return (
    <View style={styles.container}>
      <FlatList
        data={items}
        keyExtractor={(item) => item.id}
        renderItem={({ item }) => (
          <Pressable style={[styles.row, item.priority === 'high' && styles.rowHigh]}>
            <Text style={[styles.title, { color: item.priority === 'high' ? '#dc2626' : '#0f172a' }]}>{item.title}</Text>
            <Text style={styles.body}>{item.body}</Text>
            <Text style={styles.meta}>{item.type} · {new Date(item.createdAt).toLocaleString()}</Text>
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f8fafc' },
  row: {
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 14,
    marginHorizontal: 16,
    marginBottom: 10,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  rowHigh: { borderLeftColor: '#dc2626', borderLeftWidth: 4 },
  title: { fontSize: 15, fontWeight: '700', marginBottom: 4 },
  body: { fontSize: 14, color: '#334155', marginBottom: 6 },
  meta: { fontSize: 12, color: '#64748b' },
  center: { flex: 1, justifyContent: 'center' },
});

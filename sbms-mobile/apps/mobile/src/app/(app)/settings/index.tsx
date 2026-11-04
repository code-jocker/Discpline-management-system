import { View, Text, StyleSheet, TouchableOpacity, Switch } from 'react-native';
import { useAuth } from '@/hooks/useAuth';
import { Link } from 'expo-router';

export default function SettingsScreen() {
  const { user, logout } = useAuth();

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Settings</Text>

      <View style={styles.card}>
        <Text style={styles.section}>Account</Text>
        <TouchableOpacity style={styles.row}>
          <Text style={styles.rowText}>Profile</Text>
          <Text style={styles.rowValue}>{user?.fullName}</Text>
        </TouchableOpacity>
        <TouchableOpacity style={styles.row}>
          <Text style={styles.rowText}>Email</Text>
          <Text style={styles.rowValue}>{user?.email}</Text>
        </TouchableOpacity>
        <TouchableOpacity style={styles.row}>
          <Text style={styles.rowText}>Role</Text>
          <Text style={styles.rowValue}>{user?.role}</Text>
        </TouchableOpacity>
      </View>

      <View style={styles.card} style={{ marginTop: 16 }}>
        <Text style={styles.section}>Preferences</Text>
        <View style={styles.row}>
          <Text style={styles.rowText}>Push Notifications</Text>
          <Switch value={true} onValueChange={() => {}} />
        </View>
      </View>

      <View style={styles.card} style={{ marginTop: 16 }}>
        <TouchableOpacity style={styles.danger} onPress={logout}>
          <Text style={styles.dangerText}>Sign Out</Text>
        </TouchableOpacity>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 20, backgroundColor: '#f8fafc' },
  title: { fontSize: 24, fontWeight: '800', color: '#0f172a', marginBottom: 20 },
  card: { backgroundColor: '#fff', borderRadius: 14, padding: 18 },
  section: { fontSize: 13, fontWeight: '700', color: '#64748b', marginBottom: 12, textTransform: 'uppercase' },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#f1f5f9' },
  rowText: { fontSize: 15, color: '#334155' },
  rowValue: { fontSize: 15, fontWeight: '600', color: '#0f172a' },
  danger: { paddingVertical: 12, borderRadius: 10, backgroundColor: '#fee2e2', alignItems: 'center' },
  dangerText: { color: '#dc2626', fontWeight: '700' },
});

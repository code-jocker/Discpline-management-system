import { Tabs } from 'expo-router/tabs';
import { useAuth } from '@/hooks/useAuth';

export default function AppTabs() {
  const { user } = useAuth();

  return (
    <Tabs>
      <Tabs.Screen name="(app)/dashboard" options={{ title: 'Dashboard' }} />
      <Tabs.Screen name="(app)/students" options={{ title: 'Students' }} />
      <Tabs.Screen name="(app)/deduct" options={{ title: 'Quick Log' }} />
      <Tabs.Screen name="(app)/notifications" options={{ title: 'Notifications' }} />
      <Tabs.Screen
        name="(app)/settings"
        options={{ title: 'Settings', tabBarIcon: () => null }}
      />
    </Tabs>
  );
}

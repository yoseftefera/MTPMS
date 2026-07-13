import { NotificationsPage } from '@/components/notifications/NotificationsPage';
import { SectionErrorBoundary } from '@/components/ui/SectionErrorBoundary';

export const metadata = {
  title: 'Notifications — PMP',
  description: 'Your full notification history',
};

export default function NotificationsRoute() {
  return (
    <SectionErrorBoundary title="Notifications">
      <NotificationsPage />
    </SectionErrorBoundary>
  );
}

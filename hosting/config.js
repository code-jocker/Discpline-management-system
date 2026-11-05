/* Public runtime configuration for the Firebase Hosting entry page. */
window.SBMS_CONFIG = {
  // URL of the deployed PHP application, for example https://sbms.onrender.com
  // Leave empty until the backend is deployed; the page will show "Not configured".
  backendUrl: "",
  queuePath: "/parents/whatsapp-queue.php",
  helpPath: "/help/index.php",
  statusPath: "/api/whatsapp-status",
  healthPath: "/api/health",
  statusIntervalMs: 30000
};

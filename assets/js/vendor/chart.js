import { Chart, registerables } from 'chart.js/auto';
Chart.register(...registerables);
window.Chart = Chart;
export default Chart;

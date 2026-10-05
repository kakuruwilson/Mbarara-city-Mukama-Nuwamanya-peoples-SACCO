/* ============================================================
   charts.js — dashboard visualizations
   Requires Chart.js loaded locally (see js/vendor/chart.min.js and
   the README for the offline download instructions). This file only
   runs if window.Chart exists, so pages without a chart canvas are
   unaffected.
   ============================================================ */

document.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;

  var navy = '#0B2545';
  var yellow = '#F5B301';
  var green = '#1E8C63';
  var red = '#C4432B';

  /* ---------- Savings trend (line chart) ---------- */
  var savingsCanvas = document.getElementById('savingsTrendChart');
  if (savingsCanvas) {
    // Replace this sample data with real figures from php/reports.php
    var savingsData = window.SACCO_SAVINGS_TREND || {
      labels: ['Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
      values: [3200000, 3450000, 3700000, 3900000, 4250000, 4600000]
    };
    new Chart(savingsCanvas, {
      type: 'line',
      data: {
        labels: savingsData.labels,
        datasets: [{
          label: 'Total member savings (UGX)',
          data: savingsData.values,
          borderColor: navy,
          backgroundColor: 'rgba(11,37,69,0.08)',
          fill: true,
          tension: 0.35,
          pointBackgroundColor: yellow,
          pointRadius: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { ticks: { callback: function (v) { return (v / 1000000).toFixed(1) + 'M'; } } }
        }
      }
    });
  }

  /* ---------- Loan status breakdown (doughnut chart) ---------- */
  var loanCanvas = document.getElementById('loanStatusChart');
  if (loanCanvas) {
    var loanData = window.SACCO_LOAN_STATUS || {
      labels: ['Active', 'Pending approval', 'Completed'],
      values: [18, 5, 27]
    };
    new Chart(loanCanvas, {
      type: 'doughnut',
      data: {
        labels: loanData.labels,
        datasets: [{
          data: loanData.values,
          backgroundColor: [navy, yellow, green],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
      }
    });
  }

  /* ---------- Deposits vs withdrawals (bar chart) ---------- */
  var flowCanvas = document.getElementById('cashFlowChart');
  if (flowCanvas) {
    var flowData = window.SACCO_CASH_FLOW || {
      labels: ['Mar', 'Apr', 'May', 'Jun', 'Jul'],
      deposits: [820000, 910000, 875000, 960000, 1040000],
      withdrawals: [340000, 410000, 380000, 300000, 425000]
    };
    new Chart(flowCanvas, {
      type: 'bar',
      data: {
        labels: flowData.labels,
        datasets: [
          { label: 'Deposits', data: flowData.deposits, backgroundColor: green, borderRadius: 4 },
          { label: 'Withdrawals', data: flowData.withdrawals, backgroundColor: red, borderRadius: 4 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
      }
    });
  }
});

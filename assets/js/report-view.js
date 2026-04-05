(function initReportView() {
  function readJsonTextarea(id) {
    const element = document.getElementById(id);
    if (!element) {
      return [];
    }

    try {
      const parsed = JSON.parse(element.value || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
      return [];
    }
  }

  function renderProgressBars() {
    document.querySelectorAll('.dynamic-width[data-width]').forEach((element) => {
      const value = Number.parseFloat(element.getAttribute('data-width') || '0');
      element.style.width = `${Math.max(0, Math.min(100, value))}%`;
    });
  }

  function renderExpensesChart() {
    const rawLabels = readJsonTextarea('chart-labels-json');
    const rawData = readJsonTextarea('chart-values-json').map((value) => Number(value || 0));

    if (!rawLabels.length || !rawData.length) {
      return;
    }

    const canvas = document.getElementById('graficoDespesas');
    if (!canvas) {
      return;
    }

    if (typeof window.Chart === 'undefined') {
      return;
    }

    const ctx = canvas.getContext('2d');
    if (!ctx) {
      return;
    }

    new window.Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: rawLabels,
        datasets: [{
          data: rawData,
          backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#EA6A47', '#A5D8DD', '#5E92F3'],
          borderWidth: 2,
          borderColor: '#ffffff',
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
        },
        cutout: '65%',
      },
    });
  }

  function gerarPDF() {
    const element = document.getElementById('conteudo-pdf');
    if (!element) {
      return;
    }

    const filenameStem = element.getAttribute('data-filename-stem') || 'Diagnostico_Financeiro';
    if (typeof window.html2pdf === 'undefined') {
      window.alert('Nao foi possivel carregar o gerador de PDF. Verifique sua conexao e tente novamente.');
      return;
    }

    window.html2pdf().set({
      margin: 10,
      filename: `${filenameStem}.pdf`,
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
    }).from(element).save();
  }

  function bindButtons() {
    const printReportBtn = document.getElementById('printReportBtn');
    if (printReportBtn) {
      printReportBtn.addEventListener('click', () => {
        window.print();
      });
    }

    const downloadPdfBtn = document.getElementById('downloadPdfBtn');
    if (downloadPdfBtn) {
      downloadPdfBtn.addEventListener('click', () => {
        gerarPDF();
      });
    }
  }

  renderProgressBars();
  bindButtons();
  renderExpensesChart();
})();
